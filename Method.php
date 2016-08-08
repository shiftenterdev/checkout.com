<?php
namespace Dfe\CheckoutCom;
use com\checkout\ApiServices\Cards\ResponseModels\Card;
use com\checkout\ApiServices\Charges\ChargeService;
use com\checkout\ApiServices\Charges\RequestModels\ChargeCapture;
use com\checkout\ApiServices\Charges\RequestModels\ChargeRefund;
use com\checkout\ApiServices\Charges\RequestModels\ChargeUpdate;
use com\checkout\ApiServices\Charges\RequestModels\ChargeVoid;
use com\checkout\ApiServices\Charges\ResponseModels\Charge as ChargeResponse;
use com\checkout\helpers\ApiHttpClientCustomException as CE;
use Df\Payment\PlaceOrder;
use Df\Sales\Model\Order\Payment as DfPayment;
use Dfe\CheckoutCom\Settings as S;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException as LE;
use Magento\Payment\Model\Info as I;
use Magento\Payment\Model\InfoInterface as II;
use Magento\Payment\Model\Method\AbstractMethod as M;
use Magento\Sales\Model\Order as O;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment as OP;
use Magento\Sales\Model\Order\Payment\Transaction;
class Method extends \Df\Payment\Method {
	/**
	 * 2016-05-09
	 * A «Flagged» payment can be handled the same way as an «Authorised» payment:
	 * we can «capture» or «void» it.
	 * @override
	 * @see \Df\Payment\Method::acceptPayment()
	 * @param II|I|OP $payment
	 * @return bool
	 */
	public function acceptPayment(II $payment) {
		// 2016-03-15
		// The obvious $this->charge($payment) is not quite correct,
		// because an invoice will not be created in this case.
		$payment->capture();
		return true;
	}

	/**
	 * 2016-03-07
	 * @override
	 * @see \Df\Payment\Method::::authorize()
	 * @param II|I|OP $payment
	 * @param float $amount
	 * @return $this
	 */
	public function authorize(II $payment, $amount) {
		return $this->charge($payment, $amount, $capture = false);
	}

	/**
	 * 2016-03-07
	 * @override
	 * @see \Df\Payment\Method::canCapture()
	 * @return bool
	 */
	public function canCapture() {return true;}

	/**
	 * 2016-03-08
	 * @override
	 * @see \Df\Payment\Method::canCapturePartial()
	 * @return bool
	 */
	public function canCapturePartial() {return true;}

	/**
	 * 2016-03-08
	 * @override
	 * @see \Df\Payment\Method::canRefund()
	 * @return bool
	 */
	public function canRefund() {return true;}

	/**
	 * 2016-03-08
	 * @override
	 * @see \Df\Payment\Method::canRefundPartialPerInvoice()
	 * @return bool
	 */
	public function canRefundPartialPerInvoice() {return true;}

	/**
	 * 2016-05-08
	 * I have disabled the Review mode, because if Checkout.com wants 3D-Secure,
	 * the shop can not prevent it, and it will break the Review mode,
	 * because a shop's administrator is unable to pass 3D-Secure validation
	 * for a customer's bank card.
	 *
	 * 2016-05-09
	 * If Checkout.com marks a payment as «Flagged»,
	 * then it ignores the «autoCapture» request parameter,
	 * so the shop should additionally do the «capture» operation: https://mage2.pro/t/1565
	 * So we can employ the Review mode for such payments.
	 *
	 * @override
	 * @see \Df\Payment\Method::canReviewPayment()
	 * @return bool
	 */
	public function canReviewPayment() {return true;}

	/**
	 * 2016-03-15
	 * @override
	 * @see \Df\Payment\Method::canVoid()
	 * @return bool
	 */
	public function canVoid() {return true;}

	/**
	 * 2016-03-06
	 * @override
	 * @see \Df\Payment\Method::capture()
	 *
	 * @param II|I|OP $payment
	 *
	 * $amount is a money value in the shop's base currency.
	 * https://github.com/magento/magento2/blob/6ce74b2/app/code/Magento/Sales/Model/Order/Payment/Operations/CaptureOperation.php#L37-L37
	 * https://github.com/magento/magento2/blob/6ce74b2/app/code/Magento/Sales/Model/Order/Payment/Operations/CaptureOperation.php#L76-L82
	 * @param float $amount
	 *
	 * @return $this
	 */
	public function capture(II $payment, $amount) {
		if (!$payment[self::WEBHOOK_CASE]) {
			$this->charge($payment, $amount);
		}
		else {
			/**
			 * 2016-05-11
			 * A Webhook scenario.
			 * We set the Checkout.com payment ID the Magento transaction ID
			 * to prevent Magento from generating a transaction ID like <Parent Identifier>-capture.
			 */
			df_payment_apply_custom_transaction_id($payment);
		}
		return $this;
	}

	/**
	 * 2016-05-09
	 * A «Flagged» payment can be handled the same way as an «Authorised» payment:
	 * we can «capture» or «void» it.
	 * @override
	 * @see \Df\Payment\Method::denyPayment()
	 * @param II|I|OP  $payment
	 * @return bool
	 */
	public function denyPayment(II $payment) {
		/**
		 * 2016-05-09
		 * Similar to https://github.com/magento/magento2/blob/ffea3cd/app/code/Magento/Sales/Controller/Adminhtml/Order/VoidPayment.php#L22
		 */
		$payment->void(new \Magento\Framework\DataObject());
		return true;
	}

	/**
	 * 2016-05-11
	 * Mark an event as already processed,
	 * so when Checkout.com will notify us about the event,
	 * we will just skip this notification.
	 * @param string $transactionId
	 * @param string $eventId
	 * @return void
	 */
	public function disableEvent($transactionId, $eventId) {
		/** @var ChargeResponse $charge */
		$charge = $this->api()->getCharge($transactionId);
		/** @var array(string => string) $metadata */
		$metadata = df_nta($charge->getMetadata());
		/** @var string[] $events */
		$events = df_csv_parse(dfa($metadata, self::DISABLED_EVENTS, ''));
		if (!in_array($eventId, $events)) {
			$events[]= $eventId;
		}
		$metadata[self::DISABLED_EVENTS] = df_csv($events);
		// 2016-05-11
		// «Update a charge» https://github.com/CKOTech/checkout-php-library/wiki/Charges#update-a-charge
		/** @var ChargeUpdate $update */
		$update = new ChargeUpdate;
		$update->setChargeId($transactionId);
		$update->setMetadata($metadata);
		$this->api()->UpdateCardCharge($update);
	}

	/**
	 * @override
	 * @see \Df\Payment\Method::getConfigPaymentAction()
	 * @return string
	 *
	 * 2016-05-07
	 * We can arrive here only from @used-by \Magento\Sales\Model\Order\Payment::place()
	 * but from 2 different code points.
	 *
	 * 2016-05-08
	 * Returns null, if 3D-Secure validation is needed.
	 * @used-by \Magento\Sales\Model\Order\Payment::place()
	 * https://github.com/magento/magento2/blob/ffea3cd/app/code/Magento/Sales/Model/Order/Payment.php#L334-L355
	 *
	 * 2016-05-09
	 * If Checkout.com marks a payment as «Flagged»,
	 * then it ignores the «autoCapture» request parameter,
	 * so the shop should additionally do the «capture» operation: https://mage2.pro/t/1565
	 * So we can employ the Review mode for such payments.
	 */
	public function getConfigPaymentAction() {return $this->redirectUrl() ? null : $this->r()->action();}

	/**
	 * 2016-03-15
	 * @override
	 * @see \Df\Payment\Method::getInfoBlockType()
	 * @return string
	 */
	public function getInfoBlockType() {return \Magento\Payment\Block\Info\Cc::class;}

	/**
	 * 2016-03-15
	 * 2016-05-11
	 * Checkout.com refund request supports a description
	 * and a list of refunded items (products with prices and quantities):
	 * http://docs.checkout.com/reference/merchant-api-reference/charges/charge-actions/refund-card-charge
	 * @todo Use this feature.
	 * @override
	 * @see \Df\Payment\Method::refund()
	 * @param II|I|OP|DfPayment $payment
	 * @param float $amount
	 * @return $this
	 */
	public function refund(II $payment, $amount) {
		if ($payment[self::WEBHOOK_CASE]) {
			/**
			 * 2016-05-11
			 * A Webhook scenario.
			 * We set the Checkout.com payment ID the Magento transaction ID
			 * to prevent Magento from generating a transaction ID like <Parent Identifier>-refund.
			 */
			df_payment_apply_custom_transaction_id($payment);
		}
		else {
			$this->leh(function() use($payment, $amount) {
				/** @var ChargeRefund $refund */
				$refund = new ChargeRefund;
				/**
				 * 2016-05-09
				 * The «capture» transaction's ID differs from the previous transaction's ID.
				 * We should use the «capture» transaction's ID
				 * as a parameter of the Checkout.com «refund» transaction.
				 *
				 * $payment->getRefundTransactionId() and $payment->getParentTransactionId()
				 * return the same value.
				 *
				 * «refund_transaction_id» is set here:
				 * https://github.com/magento/magento2/blob/ffea3cd/app/code/Magento/Sales/Model/Order/Payment.php#L652
				 */
				$refund->setChargeId($payment->getRefundTransactionId());
				$refund->setValue(self::amount($payment, $amount));
				$this->disableEvent($payment->getRefundTransactionId(), 'charge.refunded');
				/** @var ChargeResponse $response */
				$response = $this->api()->refundCardChargeRequest($refund);
				/**
				 * 2016-05-09
				 * A sample success response:
					{
						"id": "charge_test_033B66645E5K7A9812E5",
						"originalId": "charge_test_427BB6745E5K7A9813C9",
						"responseMessage": "Approved",
						"responseAdvancedInfo": "Approved",
						"responseCode": "10000",
						"status": "Refunded",
				 		<...>
					}
				 */
				df_assert_eq('Refunded', $response->getStatus());
				$payment->setTransactionId($response->getId());
			});
		}
		return $this;
	}

	/**
	 * 2016-05-08
	 * @param ChargeResponse $response
	 */
	public function responseSet(ChargeResponse $response) {$this->_response = $response;}

	/**
	 * 2016-05-03
	 * https://github.com/CKOTech/checkout-php-library/wiki/Charges#void-a-charge
	 * @override
	 * @see \Df\Payment\Method::void()
	 * @param II|I|OP $payment
	 * @return $this
	 */
	public function void(II $payment) {
		if ($payment[self::WEBHOOK_CASE]) {
			/**
			 * 2016-05-11
			 * A Webhook scenario.
			 * We set the Checkout.com payment ID the Magento transaction ID
			 * to prevent Magento from generating a transaction ID like <Parent Identifier>-void.
			 */
			df_payment_apply_custom_transaction_id($payment);
		}
		else {
			$this->leh(function() use($payment) {
				/** @var Transaction|false|null $auth */
				$auth = $payment->getAuthorizationTransaction();
				if ($auth) {
					/** @var ChargeVoid $void */
					$void = new ChargeVoid;
					/**
					 * 2016-05-03
					 * http://developers.checkout.com/docs/server/api-reference/charges/void-card-charge#request-payload-fields
					 * Although the documentation states that the «track_id» parameter is optional,
					 * the transaction will fail with empty ChargeVoid object:
					  {
					 	"errorCode": "70000",
					 	"message": "Validation error",
					 	"errors": ["An error was experienced while parsing the payload. Please ensure that the structure is correct."],
					 	"errorMessageCodes": ["70002"],
					 	"eventId": "cce6a001-ff91-451d-9b9e-0094d3c57984"
					 }
					 */
					$void->setTrackId($payment->getOrder()->getIncrementId());
					$this->disableEvent($auth->getTxnId(), 'charge.voided');
					/** @var ChargeResponse $response */
					$response = $this->api()->voidCharge($auth->getTxnId(), $void);
					/**
					 * 2016-05-13
					 * This makes the «void» transaction ID the same in Magento and in Checkout.com
					 * (prevents Magento from generating a transaction ID like <Parent Identifier>-void).
					 */
					$payment->setTransactionId($response->getId());
				}
			});
		}
		return $this;
	}

	/**
	 * 2016-05-03
	 * @override
	 * @see \Df\Payment\Method::iiaKeys()
	 * @used-by \Df\Payment\Method::assignData()
	 * @return string[]
	 */
	protected function iiaKeys() {return [self::$TOKEN];}

	/**
	 * 2016-05-08
	 * Similarly to @see \Magento\Sales\Model\Order\Payment::processAction()
	 * https://github.com/magento/magento2/blob/ffea3cd/app/code/Magento/Sales/Model/Order/Payment.php#L414
	 * and @see \Magento\Sales\Model\Order\Payment\Operations\AuthorizeOperation::authorize()
	 * https://github.com/magento/magento2/blob/ffea3cd/app/code/Magento/Sales/Model/Order/Payment/Operations/AuthorizeOperation.php#L36-L36
	 * @return float
	 */
	private function _amount() {
		if (!isset($this->{__METHOD__})) {
			$this->{__METHOD__} = $this->ii()->formatAmount($this->o()->getBaseTotalDue(), true);
		}
		return $this->{__METHOD__};
	}

	/**
	 * 2016-04-21
	 * https://github.com/CKOTech/checkout-php-library#example
	 * https://github.com/CKOTech/checkout-php-library/wiki/Charges#creates-a-charge-with-cardtoken
	 * @return ChargeService
	 */
	private function api() {return S::s()->apiCharge();}

	/**
	 * 2016-05-11
	 * @used-by \Dfe\CheckoutCom\Method::charge()
	 * @param Transaction $auth
	 * @param II|I|OP $payment
	 * @param float|null $amount [optional]
	 * @return void
	 */
	private function capturePreauthorized(Transaction $auth, II $payment, $amount = null) {
		$this->leh(function() use($auth, $payment, $amount) {
			/**
			 * 2016-05-03
			 * https://github.com/CKOTech/checkout-php-library/wiki/Charges#capture-a-charge
			 */
			/** @var ChargeCapture $capture */
			$capture = new ChargeCapture;
			$this->disableEvent($auth->getTxnId(), 'charge.captured');
			$capture->setChargeId($auth->getTxnId());
			/**
			 * 2016-05-03
			 * «Positive integer (without decimal separator) representing the capture amount.
			 * Cannot exceed the authorised charge amount.
			 * Partial captures (capture amount is less than the authorised amount) are allowed.
			 * Only one partial capture is allowed per authorised charge.
			 * If not specified, the default is authorisation charge amount.»
			 * http://docs.checkout.com/reference/merchant-api-reference/charges/charge-actions/capture-card-charge#request-payload-fields
			 */
			$capture->setValue(self::amount($payment, $amount));
			/** @var ChargeResponse $response */
			$response = $this->api()->CaptureCardCharge($capture);
			/**
			 * 2016-06-08
			 * A sample success response:
				{
					"id": "charge_test_910FE7244E5J7A98EFFA",
					"originalId": "charge_test_352BA7344E5Z7A98EFE5",
					"liveMode": false,
					"created": "2016-05-08T11:01:06Z",
					"value": 31813,
					"currency": "USD",
					"trackId": "ORD-2016/05-00123",
					"chargeMode": 2,
					"responseMessage": "Approved",
					"responseAdvancedInfo": "Approved",
					"responseCode": "10000",
					"status": "Captured",
					"hasChargeback": "N"
			 		...
				}
			 */
			df_assert_eq(Response::S__CAPTURED, $response->getStatus());
			/**
			 * 2016-05-09
			 * As you can see in the sample response above,
			 * the «capture» transaction's ID differs from the ID of the previous transaction.
			 * We would need the «capture» transaction's ID for a future «refund» transaction,
			 * so save it.
			 * The payment is already assigned an autogenerated transaction ID like
			 * <the previous transaction>-capture,
			 * so we owerwrite the ID when we set the new one.
			 */
			$payment->setTransactionId($response->getId());
		});
	}

	/**
	 * 2016-03-07
	 * @override
	 * @see \Df\Payment\Method::capture()
	 * @param II|I|OP $payment
	 * @param float|null $amount [optional]
	 * @param bool|null $capture [optional]
	 * @return $this
	 * @throws Exception
	 */
	private function charge(II $payment, $amount = null, $capture = true) {
		/** @var Transaction|false|null $auth */
		$auth = !$capture ? null : $payment->getAuthorizationTransaction();
		if ($auth) {
			$this->capturePreauthorized($auth, $payment, $amount);
		}
		else {
			/**
			 * 2016-04-23
			 * http://developers.checkout.com/docs/server/api-reference/charges/charge-with-card-token#response
			 */
			/** @var ChargeResponse $response */
		    $response = $this->response();
			if (!$this->r()->valid()) {
				throw new Exception($this->r());
			}
			/**
			 * 2016-05-02
			 * Without it, a «void» operation will be unavailable:
			 * «How is a payment authorization voiding implemented?»
			 * https://mage2.pro/t/938
			 * https://github.com/magento/magento2/blob/8fd3e8/app/code/Magento/Sales/Model/Order/Payment.php#L540-L555
			 * @used-by \Magento\Sales\Model\Order\Payment::canVoid()
			 */
			$payment->setTransactionId($this->r()->magentoTransactionId());
			/** @var Card $card */
			$card = $response->getCard();
			/**
			 * 2016-05-02
			 * https://mage2.pro/t/941
			 * «How is the \Magento\Sales\Model\Order\Payment's setCcLast4() / getCcLast4() used?»
			 */
			$payment->setCcLast4($card->getLast4());
			// 2016-05-02
			$payment->setCcType($card->getPaymentMethod());
			/**
			 * 2016-03-15
			 * Without it, a «void» operation will be unavailable:
			 * https://github.com/magento/magento2/blob/8fd3e8/app/code/Magento/Sales/Model/Order/Payment.php#L540-L555
			 * @used-by \Magento\Sales\Model\Order\Payment::canVoid()
			 * The transaction is considered complete unless «false» is explicitly specified.
			 */
			$payment->setIsTransactionClosed($capture);
			if ($this->r()->flagged()) {
				/**
				 * 2016-05-06
				 * Unfortunately, we can set
				 * the @see \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW state here
				 * with the code $order->setState(O::STATE_PAYMENT_REVIEW);
				 * because the state will be owerwritten:
				 * @see \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand::execute()
				 * https://github.com/magento/magento2/blob/135f967/app/code/Magento/Sales/Model/Order/Payment/State/AuthorizeCommand.php#L15-L49
				 *
				 * So, we are acting differently.
				 * The «IsTransactionPending» flag will be read
				 * in the same method:
				 * @used-by \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand::execute()
				 * https://github.com/magento/magento2/blob/135f967/app/code/Magento/Sales/Model/Order/Payment/State/AuthorizeCommand.php#L26-L31
				 * And the @see Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW state will be set.
				 */
				$payment->setIsTransactionPending(true);
				/**
				 * 2016-05-09
				 * How is @used-by \Magento\Sales\Model\Order\Payment.::getIsFraudDetected()
				 * implemented and used?
				 * https://mage2.pro/t/1574
				 */
				$payment->setIsFraudDetected(true);
			}
		}
		return $this;
	}

	/**
	 * 2016-05-08
	 * We use @uses \Dfe\CheckoutCom\Method::actionDesired()
	 * instead of @see \Dfe\CheckoutCom\Method::action()
	 * to avoid a infinite recursion.
	 *
	 * If Checkout.com marks a payment as «flagged»,
	 * then the @see \Dfe\CheckoutCom\Method::isCaptureDesired() method's result will be ignored,
	 * and the «authorize» action will be used instead.
	 * @return bool
	 */
	private function isCaptureDesired() {
		return M::ACTION_AUTHORIZE_CAPTURE === S::s()->actionDesired($this->o()->getCustomerId());
	}

	/**
	 * 2016-04-23
	 * @param callable $function
	 * @return mixed
	 * @throws \Exception
	 */
	private function leh($function) {
		/** @var string|null $label */
		if (!$this->needLog()) {
			$label = null;
		}
		else {
			/** @var array(string => string|int) $bt1 */
			$bt1 = debug_backtrace()[1];
			$label = $bt1['class'] . '::' . $bt1['function'];
			$this->log($label . ' BEFORE');
		}
		/** @var mixed $result */
		try {$result = $function();}
		catch (CE $e) {throw new LE(__($e->getErrorMessage()), $e);}
		catch (\Exception $e) {throw $e;}
		if ($label) {
			$this->log($label . ' AFTER');
		}
		return $result;
	}

	/**
	 * 2016-05-15
	 * @param string $message
	 * @return void
	 */
	private function log($message) {df_log($message);}

	/**
	 * 2016-05-15
	 * @return bool
	 */
	private function needLog() {return true;}

	/**
	 * 2016-05-15
	 * @return Response
	 */
	private function r() {
		if (!isset($this->{__METHOD__})) {
			$this->{__METHOD__} = Response::sp($this->response(), $this->o());
		}
		return $this->{__METHOD__};
	}

	/**
	 * 2016-05-08
	 * A sample response when a 3D-Secure validation is needed:
		{
			"id": "pay_tok_8fba2ead-625c-420d-80bf-c831d82951f4",
			"liveMode": false,
			"chargeMode": 2,
			"responseCode": "10000",
			"redirectUrl": "https://sandbox.checkout.com/api2/v2/3ds/acs/55367"
		}
	 * @return string|null
	 */
	private function redirectUrl() {
		if (!isset($this->{__METHOD__})) {
			/** @var string|null $result */
			$result = $this->r()->a('redirectUrl');
			if ($result) {
				/**
				 * 2016-05-07
				 * If a 3D-Secure validation is needed,
				 * then $response->getId() returns a token (see a sample response above),
				 * not the transaction's ID.
				 * In this case, we postpone creating a Magento transaction yet,
				 * so we do not call $payment->setTransactionId($response->getId());
				 */
				$this->iiaSet(PlaceOrder::DATA, $result);
				/**
				 * 2016-05-06
				 * Postpone sending an order confirmation email to the customer,
				 * because the customer should pass 3D-Secure validation first.
				 * «How is a confirmation email sent on an order placement?» https://mage2.pro/t/1542
				 */
				$this->o()->setCanSendNewEmailFlag(false);
			}
			$this->{__METHOD__} = df_n_set($result);
		}
		return df_n_get($this->{__METHOD__});
	}

	/**
	 * 2016-05-07
	 * https://github.com/CKOTech/checkout-php-library/blob/V1.2.3/com/checkout/ApiServices/Charges/ResponseModels/Charge.php#L123
	 * @return ChargeResponse
	 */
	private function response() {
		if (!isset($this->_response)) {$this->_response = self::leh(function() {
			return $this->api()->chargeWithCardToken(Charge::build(
				$this->ii(), $this->iia(self::$TOKEN), $this->_amount(), $this->isCaptureDesired()
			));
		});}
		return $this->_response;
	}

	/**
	 * 2016-05-08
	 * @used-by \Dfe\CheckoutCom\Method::response()
	 * @used-by \Dfe\CheckoutCom\Method::responseSet()
	 * @var ChargeResponse
	 */
	private $_response;

	/**
	 * 2016-03-26
	 * @used-by \Dfe\CheckoutCom\Method::capture()
	 * @used-by \Dfe\CheckoutCom\Method::refund()
	 * @used-by \Dfe\CheckoutCom\Handler\Charge::payment()
	 */
	const WEBHOOK_CASE = 'dfe_webhook_case';

	/**
	 * 2016-02-29
	 * @used-by mage2pro/checkout.com/etc/di.xml
	 * @used-by mage2pro/checkout.com/etc/frontend/di.xml
	 * @used-by \Df\Payment\Method::codeS()
	 * @used-by https://code.dmitry-fedyuk.com/m2e/checkout.com/blob/fa6d87f/etc/di.xml#L9
	 */
	const CODE = 'dfe_checkout_com';

	/**
	 * 2016-05-11
	 * It is a metadata key, which stores the list of Checkout.com events,
	 * which we will ignore (do not process), because they are already processed.
	 * @used-by \Dfe\CheckoutCom\Method::disableEvent()
	 * @used-by \Dfe\CheckoutCom\Handler::isInitiatedByMyself()
	 */
	const DISABLED_EVENTS = 'disabled_events';

	/**
	 * 2016-03-06
	 * @used-by \Dfe\CheckoutCom\Method::iiaKeys()
	 * @used-by \Dfe\CheckoutCom\Method::response()
	 * @var string
	 */
	private static $TOKEN = 'token';

	/**
	 * 2016-04-21
	 * http://docs.checkout.com/reference/merchant-api-reference/charges/charge-with-card-token#request-payload-fields
	 * Expressed as a non-zero positive integer (i.e. decimal figures not allowed).
	 * Divide Bahraini Dinars (BHD), Kuwaiti Dinars (KWD),
	 * Omani Rials (OMR) and Jordanian Dinars (JOD) into 1000 units
	 * (e.g. "value = 1000" is equivalent to 1 Bahraini Dinar).
	 * Divide all other currencies into 100 units
	 * (e.g. "value = 100" is equivalent to 1 US Dollar).
	 * @param $payment II|I|OP
	 * @param float $amount
	 * @return int
	 */
	public static function amount(II $payment, $amount) {
		return ceil($amount * self::amountFactor($payment));
	}

	/**
	 * @param $payment II|I|OP
	 * @param int $amount
	 * @return float
	 */
	public static function amountReverse(II $payment, $amount) {
		return $amount / self::amountFactor($payment);
	}

	/**
	 * 2016-05-06
	 * @param $payment II|I|OP
	 * @return int
	 */
	private static function amountFactor(II $payment) {
		/** @var string[] $m1000 */
		static $m1000 = ['BHD', 'KWD', 'OMR', 'JOD'];
		return in_array($payment->getOrder()->getBaseCurrencyCode(), $m1000) ? 1000 : 100;
	}
}
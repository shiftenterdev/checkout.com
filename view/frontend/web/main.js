// 2016-05-18
define([
	'df', 'Df_StripeClone/main', 'jquery'
], function(df, parent, $) {'use strict';
/** 2017-09-06 @uses Class::extend() https://github.com/magento/magento2/blob/2.2.0-rc2.3/app/code/Magento/Ui/view/base/web/js/lib/core/class.js#L106-L140 */	
return parent.extend({
	/**
	 * 2016-07-16
	 * http://docs.checkout.com/getting-started/testing-and-simulating-charges#response-codes
	 * @override
	 * @see mage2pro/core/Payment/view/frontend/web/mixin.js
	 * @returns {String}
	 */
	debugMessage: df.c(function() {
		/** @type {?String} */
		var message = ({
			'05': '	Declined - Do Not Honour'
			,'12': 'Invalid Transaction'
			,'14': 'Invalid Card Number'
			,'51': 'Insufficient Funds'
			,'62': 'Restricted Card'
			,'63': 'Security Violation'
		})[this.amountLast2()];
		return !message ? '' : df.t(
			'The transaction will <b><a href="{url}" target="_blank">fail</a></b> with the message «<b>{message}</b>», because the payment amount (<b>{amount}</b>) in the payment currency (<b>{currency}</b>) ends with «<b>{last2}</b>».'
			,{
				amount: this.amountPD()
				,currency: this.paymentCurrency().name
				,last2: this.amountLast2()
				,message: message
				,url: 'http://docs.checkout.com/getting-started/testing-and-simulating-charges#response-codes'
			}
		);
	}),
	/**
	 * 2016-05-18
	 * 2017-02-05 The bank card network codes: https://mage2.pro/t/2647
	 * @override
	 * @see Df_Payment/main::getCardTypes()
	 * @used-by https://github.com/mage2pro/core/blob/3.9.12/Payment/view/frontend/web/template/card/fields.html#L4
	 * @returns {String[]}
	 */
	getCardTypes: function() {return ['VI', 'MC', 'AE'];},
	/**
	 * 2016-04-14 http://docs.checkout.com/getting-started/checkoutkit-js
	 * 2016-06-01
	 * An unregistered buyer can change his email, so we do not initiate CheckoutKit just now,
	 * and do it only on the «Place Order» button click.
	 * @override
	 * @see Df_Payment/card::initialize()
	 * https://github.com/mage2pro/core/blob/2.4.21/Payment/view/frontend/web/card.js#L77-L110
	 * @returns {exports}
	*/
	initialize: function() {
		this._super();
		this.initDf();
		return this;
	},
	/**
	 * 2016-03-08
	 * @used-by initialize()
	 * @used-by placeOrder()
	 * @returns {Promise}
	*/
	initDf: df.c(function() {
		/** @type {Deferred} */
		var deferred = $.Deferred();
		var _this = this;
		window.CKOConfig = {
			/**
			 * 2016-04-20
			 * This flag only triggers showing debugging messages in the console
			 *
			 * «Setting debugMode to true is highly recommended during the integration process;
			 * the browser’s console will display helpful information
			 * such as key events including event data and/or any issues found.»
			 * http://docs.checkout.com/getting-started/checkoutkit-js
			 *
			 * http://docs.checkout.com/reference/checkoutkit-js-reference/actions
			 * «The log action will only log messages on the console if debugMode is set to true.»
			 */
			debugMode: this.isTest()
			,publicKey: this.publicKey()
			,ready: function(event) {deferred.resolve();}
			,apiError: function(event) {deferred.reject();}
		};
		/** @type {String} */
		var lib = 'https://cdn.checkout.com/' + (this.isTest() ? 'sandbox/' : '') + 'js/checkoutkit.js';
		require.undef(lib);
		delete window.CheckoutKit;
		// 2016-04-11
		// CheckoutKit не использует AMD и прикрепляет себя к window.
		require([lib], function() {});
		return deferred.promise();
	}),
	/**
	 * @override
	 * @see Df_StripeClone/main::placeOrder()
	 * @used-by Df_Payment/main.html:
	 *		<button
	 *			class="action primary checkout"
	 *			type="submit"
	 *			data-bind="
	 *				click: placeOrder
	 *				,css: {disabled: !isPlaceOrderActionAllowed()}
	 *				,enable: dfIsChosen()
	 *			"
	 *			disabled
	 *		>
	 *			<span data-bind="df_i18n: 'Place Order'"></span>
	 *		</button>
	 * https://github.com/mage2pro/core/blob/2.9.10/Payment/view/frontend/web/template/main.html#L57-L68
	 * https://github.com/magento/magento2/blob/2.1.0/lib/web/knockoutjs/knockout.js#L3863
	 * @param {this} _this
	 * @param {Event} event
	 */
	placeOrder: function(_this, event) {
		if (event) {
			event.preventDefault();
		}
		if (this.validate()) {
			// 2017-07-26 «Sometimes getting duplicate orders in checkout»: https://mage2.pro/t/4217
			this.state_waitingForServerResponse(true);
			this.initDf().done($.proxy(function() {
				/**
				 * 2016-04-21
				 * http://docs.checkout.com/reference/checkoutkit-js-reference/actions#create-card-token
				 */
				CheckoutKit.createCardToken({
					cvv: this.creditCardVerificationNumber()
					,expiryMonth: this.creditCardExpMonth()
					,expiryYear: this.creditCardExpYear()
					,number: this.creditCardNumber()
					/**
					 * 2016-04-14
					 * «Charges Required-Field Matrix»
					 * http://developers.checkout.com/docs/server/integration-guide/charges#a1
					 * http://docs.checkout.com/reference/merchant-api-reference/charges/charge-with-card-token
					 *
					 * 2016-04-17
					 * How to get the current customer's email on the frontend checkout screen?
					 * https://mage2.pro/t/1295
					 */
					,'email-address': this.dfc.email()
				}, $.proxy(function(response) {
					if ('error' === response.type) {
						// 2016-08-05
						// We can get error messages from the response:
						// response.title and response.description
						// But they are not informative and contain a text like
						// «Server Operation Failed»
						// «The last server operation failed.»
						this.showErrorMessage('It looks like you have entered incorrect bank card data.');
						this.state_waitingForServerResponse(false);
					}
					else {
						this.token = response.id;
						this.placeOrderInternal();
					}
				}, this));
			}, this));
		}
	},
	/**
	 * 2016-11-10
	 * @override
	 * @see Df_Payment/card::prefill()
	 * https://github.com/mage2pro/core/blob/2.8.3/Payment/view/frontend/web/card.js#L152-L167
	 * @used-by Df_Payment/card::initialize()
	 * https://github.com/mage2pro/core/blob/2.8.3/Payment/view/frontend/web/card.js#L134-L137
	 * @param {*} d 
	 */
	prefill: function(d) {
		if ($.isPlainObject(d)) {
			this.creditCardNumber(d['number']);
			this.creditCardExpMonth(d['expiration-month']);
			this.creditCardExpYear(d['expiration-year']);
			this.creditCardVerificationNumber(d['cvv']);
		}
	},		
});});

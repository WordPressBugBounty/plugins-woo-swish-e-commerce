=== BjornTech Swish for WooCommerce ===
Contributors: BjornTech
Tags: swish, ecommerce, payment, woocommerce, swish-handel
Requires at least: 4.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.7.5
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept Swish payments in your webshop. [See our guide here on how to set up the plugin with BjornTech as the Technical supplier](https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product).

== Description ==

Our plugin makes it possible for you to accept Swish as payment method in your webshop. This payment method suits you if you want to offer your customers fast, simple and safe mobile payments.

As addition to the plugin you need the service Swish e-commerce that you order from one of the Swedish banks offering the service. Ask your bank if it does. Swish e-commerce is a special service developed for e-commerce and differs from the normal private "Swish" service and the corporate Swish corporate. Some banks have made it possible to order the service online in their internet bank but most of the banks still require a filled in and signed form to be sent in.

The plugin supports three connection methods described below:

### Technical supplier
Swish offers the possiblity to use a technical supplier when connecting your webshop to your bank.

This is the easiest way to use the plugin. You select BjornTech as Technical Supplier in your internet bank and configure the plugin to use a technical supplier.

Read more about the status of Technical Supplier in your bank and how to configure it in our [guide](https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product)

### Local certificate

If your bank is not supporting the choice of technical supplier you can create a certificate of your own and place it on your webserver. You will find instructions for doing this in our installation description.

### Simulator

If you just want to see how the plugin will look like in your webshop or if you need to test payments in your staging or test environment you can configure the plugin to simulate payments.
A payment done via the simulator is done by a pre-configured fictive mobile number and will be automatically approved by the system. Because of this you can not see how the payment will look like in the Swish App

== Installation ==

### Installation instructions for technical supplier

1.  Locate and add the plugin in wordpress by searching for "Swish".
2.  Activate the plugin through the "Plugins" menu in WordPress.
3.  Order the "Swish handel" service from your local Swedish bank.
4.  Select BjornTech as your Technical provider. This is done in your bank website.
5.  Select "BjornTech as Technical Supplier" as Connection type.
6.  Enter your merchant Swish-number in the field "Swish-number" and your mail address in the "Account mail address" field. Save the settings.
7.  Click "Connect to service".
8.  When the confirmation mail arrives in your inbox open it and click on the activation link.
9.  Make a test payment to confirm that everything is working.

== Frequently Asked Questions ==
Q:  I have a private Swish-number (using my mobile number). Can I use this number as merchant number in the plugin?
A:  No, you need the service "Swish handel". Contact your bank to order it.
Q:  I have a corporate Swish-number (starting with 123). Can I use this number as merchant number in the plugin?
A:  No, you need the service "Swish handel". Contact your bank to order it.
Q:  The plugin installation page tells me that my system is using NSS. What does that mean?
A:  A small number of installations are using NSS as mechanism for certificate handling rather than OpenSSL. Your installation is one of theese. The plugin can be configured to work with NSS but this requires deep NSS knowledge. If you do not have this knowledge we do recommend to use our service as Technical supplier.

== Screenshots ==

== Changelog ==
= 3.7.5 =
* Verified to work with WooCommerce 10.0
* Fix: Namechange
= 3.7.4 =
* Fix: Swish loading in a manner incompatible with other plugins
= 3.7.3 =
* Verified to work with WooCommerce 9.8 and Wordpress 6.8
* New: Added option to only show Swish Gateway when SEK is selected as currency in checkout
* New: Added option to turn on improved mobile detection (enabled for new users per default)
* Fix: Swish Wait page sometimes getting stuck in cache
* Fix: Mirror number option not working when using WooCommerce blocks
* Dev: Changed the way the plugin is loaded to make sure that it is loaded after the translations are initialized
= 3.7.1 =
* Verified to work with WooCommerce 9.5 and Wordpress 6.7
* Fix: Swish label in the checkout page looked strange when using some other payment gateways alongside Swish
* Fix: Swedish numbers entered without leading 0 triggered an error
* Fix: The Swish logo shown on the wait page did not adhere to the new Swish branding guidelines
* Dev: Changed the way the plugin is loaded to ensure that it is loaded after WooCommerce is initialized
= 3.7.0 =
* New: Added React support for the new checkout page - this is automatically enabled for new users
* Fix: Ad popup shows up with every cache refresh 
= 3.6.9 =
* Fix: Swish redirect on mobile sometimes redirected you back to Swish event though the order was already paid
= 3.6.8 =
* Verified to work with WooCommerce 9.3
* New: Added support for the Swish M-payment flow
* New: Added a new checkout page that is more stable for some themes
= 3.6.7 =
* Verified to work with WooCommerce 9.2 and Wordpress 6.6
= 3.6.6 =
* Verified to work with WooCommerce 8.9
* Fix: Swish label and placeholder settings not working for the WooCommerce blocks setup
* Fix: Successful payments sometimes not being registered in WooCommerce despite working in Swish
* Fix: Error message not clear when trying to use Swish while not having gone through the whole onboarding flow
= 3.6.5 =
* Verified to work with WooCommerce 8.7 and Wordpress 6.5
* New: Will now check whether number is connected to BjornTech as a technical supplier
* Dev: Removed some deprecation code
= 3.6.4 =
* Verified to work with WooCommerce 8.6
* New: Better and faster queue handler for receiving Swish status updates
* Fix: Wrong error message appears if user has not completed onboarding correctly
* Dev: WooCommerce checkout blocks now automatically enabled for all users
* Dev: Updated logging logic
= 3.6.3 =
* Verified to work with WooCommerce 8.4
* New: WooCommerce checkout blocks support enabled automatically for new users
* New: Better onboarding flow
* Fix: Wrong error message shown when using WooCommerce Checkout blocks
* Fix: Swish icon appearing in a weird position in WooCommerce checkout blocks
* Dev: Experimental checkout is now set to new checkout
* Dev: New default settings for new users
= 3.6.2 =
* Verified to work with WooCommerce 8.3
* Fix: A fatal error is triggered whenever the plugin is present and another plugin is using the upgrader_process_complete hook
= 3.6.1 =
* Verified to work with WooCommerce 8.2 and Wordpress 6.4
* New: Added some experimental support for WooCommerce blocks checkout
* Fix: Customers entering numbers starting with 046 could not pay with Swish
* Fix: New beta Swish page crashed with some Wordpress themes
* Dev: Added more hooks at the end of payment
= 3.6.0 =
* Verified to work with WooCommerce 8.0 and Wordpress 6.3
= 3.5.9 =
* Verified to work with WooCommerce 7.9
* WC High-Performance Order Storage compatibility declaration
* New: Added the option to mirror the billing phone number in the checkout with the customers Swish number
* New: Added the option to redirect to Swish directly after initializing the payment on mobile 
* Fix: Appearance of the the new Swish wait page sometimes looking strange when combined with certain themes
* Dev: Added a filter that allows blocking of certain Swish payments as soon as they are initiatied
= 3.5.8 =
* Verified to work with WooCommerce 7.6 and Wordpress 6.2
* Fix: Orders sometimes set to Failed if customer changes payment method from Swish to another one
* Fix: Plugin settings sometimes disappearing
* Dev: Sign up link now valid for a week instead of one hour
= 3.5.7 =
* Verified to work with WooCommerce 7.3
= 3.5.6 =
* Verified to work with Wordpress 6.1
= 3.5.5 =
* Verified to work with WooCommerce 7.0
* Dev: Added payeePaymentReference filter
= 3.5.4 =
* Verified to work with WooCommerce 6.9
* New: Now shows the transaction ID before the first call to Swish
* Fix: Refund not working if Swish number has been switched in the plugin
= 3.5.3 =
* Verified to work with WooCommerce 6.8
= 3.5.2 =
* Verified to work with WooCommerce 6.7
* New: Added option to optionally use callback from Swish
= 3.5.1 =
* Verified to work with Wordpress 6.0
= 3.5.0 =
* Verified to work with WooCommerce 6.5
* Changed CSS to right-align the Swish-logo in the checkout.
* Changed from png to svg Swish-logos.
= 3.4.3 =
* Verified to work with WooCommerce 6.3
= 3.4.2 =
* Verified to work with WooCommerce 6.1 and Wordpress 5.9
* New: Added option to disable polling for payment status from Swish.
* New: Added option to disable use of shutdown hook for processing.
= 3.4.1 =
* Verified to work with WooCommerce 6.0
= 3.4.0 =
* Verified to work with WooCommerce 5.7
* Added Swish Authorization token to the settings page
* Fix: Changed translation domain for Swish thank you message
= 3.3.3 =
* Verified to work with WooCommerce 5.6
* Fix: Retrieving a Swish-payment with the "Retrieve" button failed because a faulty call.
= 3.3.2 =
* Fix: Faulty call when starting background check for pyments caused the status payment status page not to update in some cases.
= 3.3.1 =
* Verified to work with Wordpress 5.8 and WooCommerce 5.5
* Fix: Changed 'swish-completed' from id to class.
* Fix: Added logging of data sent to the Swish service.
* Fix: Error was not logged correctly the transaction sent to Swish could not be formatted.
= 3.3.0 =
* Verified to work with WooCommerce 5.3
* Fix: Klarna is setting payment-reference also on Swish-orders created by us, this caused payments not to be marked as PAID.
* Fix: In some cases the order retrevial process when handling callbacks fails due to the use of a order-child.
* Fix: The settings page did not load correclty in some browsers.
= 3.2.0 =
* New: Improved handling of callbacks. Sites with performance issues did not respond within Swish timeout time causing callbacks to fail in processing.
* New: Implemented a status check for transaction-status in order to give customers correct info also when Swish callback fails. Now the plugin asks Swish on transaction status every 10:th second on an ongoing transaction.
= 3.1.0 =
* Verified to work with WooCommerce 5.1
* Verified to work with Wordpress 5.7
* Fix: Minor clean-up of the Swish-number field.
* Fix: Removing url parameters from url before loading image.
* Fix: Callback for refunds was not correctly processed.
* Fix: Fatal error when refunding caused by logging function.
* New: Added the possibility to set a placeholder for the Swish-number field.
* New: Sending webhooks through service in order to get better trouble-shooting capabilities.
= 3.0.9 =
* Verified to work with Wordpress 5.6
* Verified to work with WooCommerce 4.8
* Fix: Improved error handling when connecting to the Swish-service
= 3.0.8 =
* Fix: Handle only one parameter instead of two in the filter 'woocommerce_gateway_icon' to handle the situation where other gateways are using only one.
= 3.0.7 =
* Fix: Jquery was using class instead of if causing order details not to show when payment is ready
* Verified to work with WooCommerce 4.7
= 3.0.6 =
* Fix: Order-id not found in checkout if url was using fragment identifier (#)
= 3.0.5 =
* Fix: Some sites did not accept PUT calls for admin, resulting in problems when connecting to service.
* Fix: The Swish-simulator connection type did not work after 3.0.0
* Fix: Error handling in two funxtions did use the wrong logging functions.
* Fix: Check payment reference for unwanted characters and lenght.
* Fix: Moved back to old callback handling
= 3.0.2 =
* Fix: Incorrect creation of UUID caused payment to generate error because of an incorrect payment reference
= 3.0.1 =
* Fix: Swish checkout image did now show for users in all themes.
= 3.0.0 =
* New: ** IMPORTANT ** Forcing the use of TLS 1.2 - Swish requires this from Nov 1st 2020. An error message will be displayed if your site does not support TLS 1.2, Contact your hosting provider if you get this error message.
* Verified to work with WooCommerce 4.6
* New: Changed checkout page to not show order details until payment is ready.
* New: Possibility to set a minimum age for purchase on the website.
* New: Possibility to set a minimum age for purchase on each product.
* New: Using WooCommerce REST api functions for callbacks.
* New: Added a filter for users of local certificates to add CURLOPT if needed.
* Fix: Changing the way to get callback url in order to be compatible with WPML permalinks
* Fix: Settings page did show error message when saving.
= 2.5.2 =
* Verified to work with WooCommerce 4.4
* Verified to work with Wordpress 5.5
= 2.5.1 =
* Fix: In some installations the faulty refund-message did still show, logic for refund handling changed to prevent this.
= 2.5.0 =
* New: Added function to retrieve payment info if callback from Swish failed.
* Fix: Incorrect handling of Swish callback caused refund messages to be incorrectly logged on successful payments. Please disregard the refund messages.
= 2.4.1 =
* Fix: New number and certificates for the test-service
= 2.4.0 =
* New: SEB and Danske bank as technical supplier.
* Fix: Activation as technical supplier failed in some cases.
* Fix: Multiple refunds did not work.
* Fix: Order is always set to 'pending' when sent.
= 2.3.2 =
* Verified to work with WooCommerce 4.0
* Verified to work with Wordpress 5.4
= 2.3.1 =
* Fix: No need for plugin to load texdomain, this is done by Wordpress
* Fix: Better matching of order id in from checkout page to prevent failure to find order if some other plugin adds something in the checout url
* Fix: The plugin did always check for a certificate file causing the check to fail and log errors if no certificate was used.
= 2.3.0 =
* Verified to work with WooCommerce 3.9.
* New: Added setting to change the name of the Swish number field.
* Fix: The reason text in refunds was not checked for lenght and illegal characters, causing refunds to fail.
* Fix: Test mode text was not translatable.
= 2.2.2 =
* New: Technical supplier works for Handelsbanken
= 2.2.1 =
* Fix: Modal information screen did not show when connecting to Technical supplier
* Fix: Additional CSS changes to get the Swish-logo in the correct size in some themes.
= 2.2.0 =
* Verified to work with Wordpress 5.3 and WooCommerce 3.8
* New: Added setting to select what status a paid order should end up in.
* Fix: The logo in modal is sometimes VERY large. Changed CSS to ensure that the size is normal
* Fix: Added message to end customer if something goes wrong when checking for payment
= 2.1.0 =
* New: Technical supplier works for Nordea
* New: Saving order id as metadata.
* New: Saving payment id in order when callback is received.
* New: Logging certificate details.
* New: Warning when certificate is about to expire or has expired.
* Fix: Preventing the learndash plugin to set the order to completed at all times.
* Fix: Swish image did not show in modal sometimes.
* Fix: Some sites using alternate checkout url format did experience "Unknown error from Swish".
* Fix: Added new BankId status messages.
= 2.0.9 =
* Fix: Minor text changes
* Verified to work with WooCommerce 3.7.0
= 2.0.8 =
* Fix: Added a better more clear error message for the cases where Technical Provider was not selected in the bank
* Fix: More checks of the Swish-number when enrolling to the Swish service.
= 2.0.7 =
* Fix: Some translations did not upload to Wordpress correctly.
= 2.0.6 =
* Fix: Config from previous version was not properly converted to the new.
= 2.0.5 =
* New: Certificate service added, no creation of certificate needed for customers using Swedbank (more banks coming soon).
* New: New modal checkout layout, select it in the configuration page. It is not working with all themes. Contact us if you have issues with modal checkout in your theme.


== Upgrade Notice ==
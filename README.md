# Vaimo_Maksuturva

This is Maksuturva's official Magento 1.x payment gateway module. The module is maintained by Vaimo.

## System requirements

The Maksuturva Payment Gateway module for Magento was tested on and requires the following set of applications in order to fully work:

* Magento 1.5.x - 1.9.x or Magento EE 1.14.x
* PHP version 4 or 5
* PHP cURL support

There is no guarantee that the module is fully functional in any other environment which does not fulfill the requirements.

## Installation

Prior to any change in your environment, it is strongly recommended to perform a full backup of the entire Magento installation.
It is also strongly recommended to do installation first in development environment, and only after that in production environment.

1. Extract the module files under Magento installation
2. Clean Magento cache
3. Configure the module
4. Verify the payments work

At this moment the module does not alter Magento's database schema in any way or create any custom database tables.

## Configuration

Configuration for the module can be found from standard location under *System -> Configuration -> Payment Methods -> Maksuturva*.

##### Sandbox mode
If enabled, communication url, seller id and secret key in sandbox fields are used, otherwise production parameters are used.

##### Seller id and secret key
This parameter provided by Maksuturva.
Please note that this key must not be shared with any person, since it allows many operations to be done in your Maksuturva account.

##### Communication url

API url to communicate with Maksuturva service. Should be usually kept as is.

##### Key Version
This parameter provided by Maksuturva. Check your secret key version and input this value into the configuration field.

##### Communication encoding

Specifies which encoding is used. Will be deprecated in future, and only UTF8 will be supported. Do not change this.

##### Preselect payment method in webshop

Enables selection of Maksuturva payment method directly on Magento checkout by dropdown selector, instead of redirecting to Maksuturva service and selecting it there.
List of allowed payment methods are fetched from Maksuturva API based on cart total. Certain methods like part payment might be available only when cart
total exceed the configured limit.

Please note that this service needs to be enabled by Maksuturva first.

###### Payment fees

Only supported when preselect payment method in webshop is enabled. Currently requires module Vaimo_PaymentFee, this might change to more generic way in future versions.

##### Delayed capture methods

In case part payment or invoice payment methods are used, this can be used to specify delayed capture for these methods.
In normal operation all payments are marked as captured when user returns to webshop from Maksuturva service. When a method is marked as
delayed capture method, on return to webshop it will not be marked as captured. In order to capture these, creation of invoice with capture case
set to "online" is required.

This is usually used if capture should be done only after shipping the goods to the customer. In case of ERP integration, the
integration is responsible of creating the invoice and thus triggering the capture.

Please note that only few methods support delayed capture, these need to be verified from Maksuturva.

The methods are given as comma separated list, example:
```
code1,code2,code3
```

##### Query Maksuturva API for orders missing payments

If enabled, will enable cronjob that queries Maksuturva API for order missing payment. This kind of orders might
occasionally happen, if after successful payment customer does not return to webshop.

It is recommended to enable this.

## Sandbox testing

Most simple way to test the payment module is to switch the Sandbox / Testing mode on. In the 
sandbox mode after confirming the order, the user is directed to a test page where you can see all the passed information and 
locate possible errors. In the sandbox page you can also test ok-, error-, cancel- and delayed payment -responses that 
Maksuturva service might send to your service.

## Testing with a separate test account

For testing the module with actual internet bank, credit card, invoice 
or part payment services, you can order a test account for yourself.

> http://test1.maksuturva.fi/MerchantSubscriptionBeginning.pmt

When ordering a test account signing the order with your TUPAS bank credentials is not required. When you have completed the 
order and stored your test account ID and secret key, we kindly ask you to contact us for us to activate the account.

In the test environment no actual money is handled and no orders tracked. For testing the internet bank payments in the test 
environment we recommend using the test services of either Nordea or Aktia banks bacause in their services the payer credentials 
are already prefilled or displayed for you. Do not try to use actual bank credentials in the test environment.

For testing our payment service without using actual money, you need to set communication URL in the module configurations as 
http://test1.maksuturva.fi. All our test environment services are found under that domain unlike our production environment 
services which are found under SSL-secured domain https://www.maksuturva.fi. Test environment for KauppiasExtranet can be found 
similarly at http://test1.maksuturva.fi/extranet/.


If sandbox testing passes but testing with test server fails, the reason most likely is in communication URL, seller id or 
secret key. In that case you should first check that they are correct and no extra spaces are added in the beginning or end of 
the inputs.

## Order status

When a customer has confirmed order and is transferred to Maksuturva payment gateway, Magento sets the order as *pending*.
When the customer returns to Magento and Maksuturva confirms the payment, an invoice is created and set as paid and the order's amounts are updated. The order status is now set as *processing**.
Magento updates order status as *complete* when the order is fully paid and shipped.

## Maksuturva API documentation

API description and documentation can be found at:

* Finnish: http://docs.maksuturva.fi/fi/html/pages/
* English: http://docs.maksuturva.fi/en/html/pages/

## Support

In case of support question or bug in the module, please contact Maksuturva at it@maksuturva.fi.

## Migration from old module version

Migration should be relatively easy in standard case, since database changes are not involved. Please note, that new module is
under new name, and old Maksuturva and Makadmin module has to be removed. Steps to take are:

1. Backup the configuration or list it somewhere
2. Completely remove old module files
3. Install new module
4. Verify configuration

It is always recommended to do this in test environment first before upgrading in production.

## Advanced features

### Split submethods to separate Magento payment methods

When using payment method preselection in checkout, instead of standard dropdown selection the methods can be split into
separate Magento payment methods. This is not officially supported, because it requires a core patch and defining customer specific models.
Split methods also have the drawback of being non-dynamic: if Maksuturva adds or removes methods later on, it will require
changes to customer specific implementation.

Split-off methods are configured as standard Magento payment methods with a custom format method code, eg. "maksuturva_fi50". The method should have it's own model
which extends Maksuturva's model. Example of this can be found from optional/example_split_method_model.php. The methods can have their own block,
in case customized template or logic is needed.

When user uses split method, on backend the code will still always be "maksuturva", and everything behaves the same way.

Splitting of methods requires applying supplied patch under optional/patches/getSelectedSubMethodCode.patch. The patch will modify core
to return selected sub-method code (eg. maksuturva_fi50 instead of just maksuturva). The patch handles the use-case when user goes back on checkout
steps, otherwise the selected sub-method would stay selected. This assumes all methods are split off from default
dropdown. If this is not the case, the patch needs modification to filter the codes split off.

## Contribution guidelines

* Vaimo: Before writing anything, please contact module maintainer (paavo.pokkinen@vaimo.com). No logic change is allowed.
* 3rd party: pull requests are welcome and are reviewed by Vaimo.
* This module is using semantic versioning, please see http://semver.org/

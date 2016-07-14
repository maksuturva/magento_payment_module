# Change Log

### 2.2.0

  * Marked cron polling functionality as deprecated (still works as earlier).
  Cron polling should be disabled from module settings, and Maksuturva asked to enable "status OK" callback to Magento.
  * Added new indexed field to sales_flat_order_payment table for Maksuturva pmt_id, in order to find correct payment based on pmt_id
  * Improved success controller to function independent of user (browser) session
  * Fixed "undefined variable" warning on sales order view in certain conditions
  * Fixed simplexml error when Maksuturva API returns only one possible payment method
  * Added possibility to change payment method title per store view

### 2.1.0

  * When using payment method preselection, it is now possible to use icon style in addition to basic dropdown on checkout

### 2.0.1

  * Removed eMaksut configurable option, as supplying it is no longer needed

### 2.0.0

  * Initial release

# CamooPay - Mobile Money Gateway for WooCommerce
CamooPay Gateway for WooCommerce is a simple and powerful Payment plugin for WordPress

[![CamooPay](includes/assets/images/camoo-pay.webp)](https://www.camoo.cm)

You can add to WordPress, the ability to receive easily Mobile Money payment from Cameroon

The usage of this plugin is completely free. You have to just have a camoo account:
* [Sign up](https://www.camoo.cm/) for a free account
* Ask Camoo SARL Team for consumerKey and consumerSecret

# Requirements
For the plugin to work, you need the following:
* WordPress 6.0 or later
* WooCommerce 3.3 or later
* PHP 8.1 or later

# Features

* Pay with Cameroon MTN Mobile Money
* Pay with Cameroon Orange Mobile Money
* Pay with Express Union Mobile Money

# Installation
We assume you already installed WooCommerce and configured it successfully

1. Upload `camoo-pay-for-ecommerce` to the `/wp-content/plugins/` directory

   Install Using GIT
```sh
cd wp-content/plugins

git clone https://github.com/camoo/camoo-woocommerce-gateway.git camoo-pay-for-ecommerce

# install dependencies
cd camoo-pay-for-ecommerce
composer install
```

## Auto installation and Manage the plugin
1. In your WordPress Dashboard, go to \"Plugins\" → \"Add Plugin\."
2. Search for \"CamooPay\".
3. Install the plugin by pressing the \"Install\" button.
4. Activate the plugin by pressing the \"Activate\" button.
5. Open the settings page for WooCommerce and click the \"Checkout\" tab.
6. Click on the sub tab for \"CamooPay for e-commerce Payment\."
7. Configure your CamooPay Gateway settings.

#### More details can be found on the [documentation website](https://www.camoo.cm)

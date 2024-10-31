# RettiPay
Contributors: rettipay
Tags: rettipay, cryptocurrency, payments, discounts, payment gateway
Requires at least: 4.6
Tested up to: 5.4
Stable tag: 1.1.2
Requires PHP: 5.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

RettiPay is a service that lets you offer discounts on your products for cryptocurrency

## Description

RettiPay works by letting you set discounts in percentages on your products for cryptocu
rrency. For example, you can set a product to accept 10% of the payment in cryptocurrenc
y, and give a 20% off discount. This means that you are still receiving 90% of the payme
nt of your product, but now your customer is incentivized their cryptocurrency that usua
lly sits in a cold wallet somewhere. Note that we do not process traditional payments - 
just the discounts. Your customers will be redirected back to your site to finish the ch
eckout process once we have verified the cryptocurrency payment.

Head on over to [RettiPay](https://rettipay.com) for more info.

## Installation

### From WordPress.org

This plugin is available on the [WordPress.org plugin repository](https://wordpress.org/plugins/rettipay), and can be installed either directly from there or from the admin dashboard within your website.

#### Within your WordPress dashboard
1. Visit ‘Plugins > Add New’
2. Search for ‘RettiPay’
3. Activate RettiPay from your Plugins page.

#### From WordPress.org plugin repository
1. Download RettiPay from <https://wordpress.org/plugins/rettipay/>
2. Upload to your ‘/wp-content/plugins/’ directory, using your favorite method (ftp, sftp, scp, etc…)
3. Activate RettiPay from your Plugins page.

### From this repository

Within this repository, click the "Clone or Download" button and Download a zip file of the repository.

Within your WordPress administration panel, go to Plugins > Add New and click the Upload Plugin button on the top of the page.

Alternatively, you can move the zip file into the `wp-content/plugins` folder of your website and unzip.

You will then need to go to your WordPress administration Plugins page, and activate the plugin.

## Frequently Asked Questions

### What is RettiPay?

RettiPay is a service that lets you easily offer discounts on your store products for cryptocurrency. These discounts can be set on a per-product level, or for your entire site.

### Is it free?

You will need to set up an account over on [RettiPay](https://rettipay.com) in order to use this plugin.

### How do the discounts work?

When the plugin is installed, each product in your store will have the option to accept RettiPay payments. From there, you will set two fields: the amount of cryptocurrency to take in, and the amount of discount to give once you've received the cryptocurrency. Note that these are in percentages, and we do all of the calculations for your customer at checkout.

## Changelog

### 1.1.2

- Removed RettiPay logo on checkout page
- Remove products from RettiPay Marketplace if they are not synced
- Various bug fixes and improvements

## 1.1.0

- Add the ability to sync RettiPay products to Discounts With Crypto. This is an online product aggregate for cryptocurrency users to view when they want to use cryptocurrency as a payment option when shopping.
- Various bug fixes and improvements

### 1.0.2

- Make sure that `assets` are commited to SVN
- Set the Stable Tag in the `readme.txt` to `trunk`

### 1.0.1

- Fix broken URL in the README file
- Move images inside of the `asset/images` folder to `assets` and remove the `assets/images` folder

### 1.0.0 
* Initial release of the RettiPay plugin

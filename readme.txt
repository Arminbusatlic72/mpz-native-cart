=== Pametna korpa za WooCommerce ===
Contributors: mojprvizalogaj
Tags: woocommerce, cart drawer, free shipping, cross-sell
Requires at least: 6.4
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.5.5
License: GPLv2 or later

Pametna bočna WooCommerce korpa s prikazom napretka do besplatne dostave i relevantnim preporukama proizvoda.

== Installation ==

1. Aktivirajte "Pametna korpa za WooCommerce" na stranici Dodaci.
2. Otvorite WooCommerce > Pametna korpa.
3. Set the free-shipping threshold to the same amount used by the shipping method.
4. Optionally enter product IDs for manual recommendations. If left empty, WooCommerce cross-sells and related products are used.

== Features ==

* Opens a side cart after AJAX add-to-cart.
* Always displays the cart drawer and progress toward a configurable target value.
* Displays the recommendation slider both below and above the target value.
* Detects classic cart-page updates and opens once when the cart crosses the target value.
* Displays free-shipping progress.
* Calculates progress from the customer-facing subtotal, including displayed tax and coupon discounts, to match WooCommerce free shipping.
* Displays the remaining free-shipping amount with the RSD currency code.
* Supports quantity changes and removal without leaving the page.
* Gives administrator-configured WooCommerce cross-sells the highest priority.
* Gives global campaign products saved in the plugin settings second priority.
* Learns anonymous product-pair frequency from up to 300 recent processing/completed orders to fill remaining positions.
* Requires at least two co-purchases before treating products as frequently bought together.
* Fills remaining positions with cross-sells, campaign products, related products, and popular-product fallbacks.
* Shows recommendations in a compact touch-friendly slider with previous/next controls.
* Keeps the compact recommendation slider visible above the subtotal on mobile while cart items scroll independently.
* Keeps recommendations inside the cart drawer, directly above the subtotal and checkout button.
* Prevents Astra's mobile cart drawer from covering the plugin drawer and recommendation slider.
* Does not alter the WooCommerce checkout page.
* Supports WooCommerce HPOS and WooCommerce product blocks.

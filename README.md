# Givver Payment Gateway plugin for WooCommerce

Givver payment gateway for WooCommerce (Credit Cards).

Documentation https://docs.givver.cards/woocommerce

## How to install

1. Open WordPress Admin Dashboard

2. Install Givver plugin
- Download plugin zip https://github.com/GivverCards/givver-woocommerce-plugin/archive/refs/heads/main.zip
- Navigate to **Plugins → Add Plugin → Upload Plugin**
- Upload the zip file `givver-woocommerce-plugin-main.zip`
- Click **Install Now**.
- After installation, click **Activate**.

3. Enable Givver payments
- Go to **WooCommerce → Settings → Payments**
- Locate **Givver - ...** in the payments list.
- Toggle the switch to **Enabled** for **Givver – Credit Card**

4. Configure API keys

Click **Manage** on **Givver – Credit Card** to open configuration settings.

You'll need to provide credentials from your Givver Dashboard:

| Setting | Description |
|--------|-------------|
| **Live Publishable API Key** | Used for generating secure tokens in Production mode |
| **Test Publishable API Key** | Used for generating secure tokens in Test mode |
| **Live Secret API Key (Admin API)** | Used for server-side API calls in Production mode|
| **Test Secret API Key (Admin API)** | Used for server-side API calls in Test mode|
| **Sandbox / Test Mode** | Sandbox or Production mode |

## Updating to 1.0.1

No new settings. The plugin now keeps the Givver webhook in the store that matches the current Secret API key:

- After you change the Secret API key and click **Save**, the webhook is registered in the store of the new key and the new signing secret is stored. Before, payments made after a key change could stay in Pending.
- Clicking **Save** without changing keys checks the webhook in Givver. If Givver disabled it after failed deliveries, the plugin enables it again. If it was deleted, the plugin creates it again.
- Switching back to a key the plugin already used reuses the same webhook, and no duplicate is created.

Existing stores need no action. After the update, the next storefront request records the current key and keeps the stored signing secret.

Keep only one webhook for your site's URL in each Givver store. If the store has several webhooks with the same URL, delete the extra ones in **Settings → Webhooks** and click **Save** in WooCommerce.

Events sent while the webhook was disabled are not delivered again. Update those orders manually.

## Local development

1. run WooCommerce instance locally

```
docker compose up
```

2. go to http://localhost:8080, register and login to your WordPress Admin Dashboard

3. install WooCommerce plugin
- Navigate to **Plugins → Add Plugin**
- Find `WooCommerce` in search
- Click **Install Now**.
- After installation, click **Activate**.

4. install Givver plugin as usual

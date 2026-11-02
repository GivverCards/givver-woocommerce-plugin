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

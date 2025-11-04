# Monek Checkout for WooCommerce

Give your WooCommerce store a secure and dependable payment experience with the Monek Checkout plugin. This guide walks you through installing, configuring, and supporting the plugin in clear, beginner-friendly language. If you only want to get the plugin running, follow the quick steps below. Developers can jump to the final section for contribution details.

## Table of contents
- [Who this guide is for](#who-this-guide-is-for)
- [Before you start](#before-you-start)
- [Installation options](#installation-options)
  - [Option A: Install from your WordPress dashboard](#option-a-install-from-your-wordpress-dashboard)
  - [Option B: Install manually from GitHub](#option-b-install-manually-from-github)
- [Configure Monek Checkout](#configure-monek-checkout)
- [Keep the plugin up to date](#keep-the-plugin-up-to-date)
- [Need help?](#need-help)
- [About this repository](#about-this-repository)
- [For developers](#for-developers)

## Who this guide is for
This documentation is written for store owners, site administrators, and support teams who want a straightforward way to install and use the Monek Checkout plugin. No developer knowledge is required for the installation and configuration steps.

## Before you start
Make sure the following items are ready before installing the plugin:

- A WordPress site with administrator access.
- WooCommerce installed and activated.
- A Monek merchant account and Monek ID (reach out to [Monek Support](https://monek.com/contact) if you need help).
- For manual installation only: the ability to upload files to your site via the WordPress admin area or an FTP/SFTP client.

> **Important:** Version 4.0 is a breaking change. It requires the latest WooCommerce Checkout Blocks experience and no longer supports the legacy shortcode-based checkout. Updating gives merchants access to the improved checkout flow delivered by WooCommerce Blocks.

## Installation options
You can install Monek Checkout directly from your WordPress dashboard or by uploading the plugin files manually. Choose the option that suits you best.

### Option A: Install from your WordPress dashboard
1. Log in to your WordPress admin area.
2. Go to **Plugins → Add New**.
3. Search for **"Monek Checkout"**.
4. Click **Install Now**, then click **Activate** once the installation finishes.
5. Continue to [Configure Monek Checkout](#configure-monek-checkout).

### Option B: Install manually from GitHub
Use this method if you prefer to control which release you install or if you are unable to use the WordPress Plugin Directory.

#### Step 1: Download the latest release
1. Visit the [Monek Checkout GitHub releases page](https://github.com/monek-ltd/Monek.Checkout.WooCommerce/releases/latest).
2. Download the ZIP file for the version you want to install. (Avoid using the `trunk` folder unless you specifically need the development build.)

#### Step 2: Prepare the plugin files
- If you downloaded a ZIP file, there is no need to extract it if you plan to upload via the WordPress admin area.
- If you are using FTP/SFTP, extract the ZIP on your computer so that you can upload the `monek-checkout` folder.

#### Step 3: Upload to WordPress
Choose one of the following upload options:

**Upload through the WordPress admin area**
1. Go to **Plugins → Add New** and click **Upload Plugin**.
2. Select the ZIP file you downloaded and click **Install Now**.
3. When prompted, click **Activate Plugin**.

**Upload via FTP/SFTP (advanced)**
1. Connect to your site using your hosting file manager or an FTP/SFTP client (for example, FileZilla).
2. Navigate to `wp-content/plugins/`.
3. Upload the entire `monek-checkout` folder into this directory.
4. Return to the WordPress admin area, go to **Plugins**, and click **Activate** under **Monek Checkout**.

Once the plugin is active, continue to the next section to complete the setup.

## Configure Monek Checkout
Follow the steps below to connect your store to Monek and enable the secure checkout experience.

### 1. Gather your API details from Odin
1. Sign in to the [Odin merchant portal](https://merchant.odin.com/) and open the **Integrations** tab.
2. Locate or create an access key for WooCommerce.
3. Copy the following values:
   - **Publishable key** (sometimes called the public key).
   - **Secret key**.
4. (Optional) If you plan to support Apple Pay, add your website domain to the access key before leaving the page.

Keep these credentials secure. You will paste them into the plugin settings in the next step.

### 2. Enter the credentials in WooCommerce
1. In the WordPress admin area, go to **WooCommerce → Settings**.
2. Open the **Payments** tab.
3. Find **Monek** in the list of payment methods and click **Manage** (or **Set up** if you have not configured it before).
4. Enable the payment method.
5. Enter your **Monek ID**, **publishable key**, and **secret key**.
6. Save your changes.

### 3. (Optional) Set up Apple Pay
Apple Pay relies on the website domain you added in the Odin **Integrations** tab. After saving your keys in the plugin settings, test the checkout flow on a supported Apple device to confirm the Apple Pay button appears. Contact Monek Support if you need help validating your domain.

### 4. (Optional) Configure a webhook for payment confirmation
1. In Odin, create or edit an SVIX webhook in the **Integrations** tab.
2. Set the destination URL to your store domain followed by `/wp-json/monek/v1/webhook` (for example, `https://example.com/wp-json/monek/v1/webhook`). The plugin exposes this REST endpoint automatically.
3. (Optional) Add the **webhook endpoint signing secret** provided by SVIX to the plugin settings. When supplied, the plugin verifies that incoming webhooks are legitimate. If you choose not to add a signing secret, all incoming webhooks are treated as valid.

When a verified webhook confirms a successful payment, the plugin can move the order into the **Payment Confirmed** status. Merchants can use this status to clearly track orders that have passed through Monek's security checks.

Test the checkout flow on a staging site or with a low-value transaction to ensure everything is working as expected.

## Keep the plugin up to date
- **From the WordPress dashboard:** Updates appear in the usual **Plugins** list. Click **Update now** when a new version is available.
- **Manual installs:** Download the latest release ZIP from GitHub and upload it using the same steps described above. WordPress will replace the existing version while keeping your settings.

## Need help?
If you run into any questions, the Monek Support team is ready to help with installation, configuration, and troubleshooting.

- Contact form: [https://monek.com/contact](https://monek.com/contact)
- WordPress support forum: [https://wordpress.org/support/plugin/monek-checkout/](https://wordpress.org/support/plugin/monek-checkout/)

## About this repository
This GitHub repository contains the source code for the Monek Checkout plugin, including:

- `assets/`: Plugin images and icons.
- `tags/`: Versioned releases that mirror what is available on WordPress.org.
- `trunk/`: The development branch that eventually becomes the next release.
- `README.md`: This documentation.

The code is also published to the WordPress Plugin Directory via the [WordPress.org SVN repository](https://plugins.svn.wordpress.org/monek-checkout).

## For developers
The information below is intended for Monek engineers and community contributors who work on the plugin codebase.

### Release expectations
- Code in `trunk/` should remain release-ready even while under active development.
- Follow the [WordPress plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) before shipping a release.
- Run the [Plugin Check tool](https://wordpress.org/plugins/plugin-check/) to validate the plugin before publishing.

### Helpful resources
- [How to use Subversion with the WordPress Plugin Directory](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [Plugin developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)
- [Readme.txt standard](https://wordpress.org/plugins/developers/#readme)
- [Readme validator](https://wordpress.org/plugins/developers/readme-validator/)
- [Plugin assets guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- [Plugin team announcements](https://make.wordpress.org/plugins/)
- [Development log for Monek Checkout](https://plugins.trac.wordpress.org/log/monek-checkout/)

### SVN reminders
- SVN usernames match your WordPress.org username and are case-sensitive.
- Set or update your SVN password in your WordPress.org profile under **Account & Security**.
- Only upload production-ready releases to `tags/`; use `trunk/` for code preparing for the next release.

By keeping these practices in mind, we ensure Monek Checkout remains stable for merchants while continuing to improve over time.

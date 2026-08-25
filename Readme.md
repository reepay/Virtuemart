=== Frisbii Pay ===
Lastest version: 1.2.1

# Joomla Virtuemart
Payment Plugin for Joomla Virtuemart 3.x, 4.x and 5.x

## Installation 
1. Download the .zip file from https://github.com/reepay/Virtuemart/releases
2. Log in to your Joomla Administrator panel
3. Go to **System** > **Install** > **Extensions**
4. Under the **Upload Package File** tab, click **Browse Button** and select the
   downloaded .zip file, then click **Upload & Install**
5. Once installed, go to **Components** > **Virtuemart** > **Payment Methods**
6. Click **New** and select **Frisbii Payments Gateway** as the payment method
7. Configure the plugin settings (API key, checkout type, etc.) and click **Save**

## Requirements
 - Joomla Virtuemart >= 3.2.0 (also might work on lower versions but has not been tested)
 - php >= 7.0

 == Changelog ==
v 1.2.1
- [Fix] - Fixed fatal error on PHP 8+ when calling API requests without parameters (e.g. getWebhooks()).
- [Docs] - Updated Readme with detailed step-by-step installation guide for Joomla.

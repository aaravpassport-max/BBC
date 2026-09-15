# RTOFLOW OS — Enterprise RTO Service Management Plugin

A full-stack WordPress plugin for managing RTO (Road Transport Office) services,
vendors, client orders, payments, invoicing, and notifications.

## Requirements
- WordPress 6.0+
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.4+

## Installation
1. Upload the `rtoflow-os` folder to `/wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Visit **Settings → Permalinks** and click **Save Changes** (activates portal URLs)
4. Visit **RTOFLOW OS → Setup & Status** to complete configuration

## Portal URLs
| Portal | URL | Access |
|--------|-----|--------|
| Admin | /rto-admin/ | rto_admin role |
| Vendor | /rto-vendor/ | rto_vendor role |
| Client | /rto-dashboard/ | rto_client role |
| Apply | /rto-apply/ | Public |
| Services | /rto-service/ | Public |

## Configuration
All settings available from **RTOFLOW OS → Settings** in WP Admin:
- Company details, GSTIN, TAN
- Razorpay payment gateway
- MSG91/Fast2SMS SMS provider
- WhatsApp Business API
- Notification preferences

For security-sensitive production deployments, optionally create a `.env` file
(copy from `env.example`) to store secrets outside the database.

## Version
3.4.0

<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$legal_eyebrow = 'Legal';
$legal_title   = 'Privacy <span>Policy</span>';
$legal_intro   = 'How ' . $brand . ' collects, uses, and protects your personal information when you book newspaper ads through our platform.';
$legal_sections = [
    [
        'heading' => 'Information We Collect',
        'body'    => '<p>We collect information you provide when booking an ad, creating an account, or contacting support — including name, email, phone number, billing details, ad content, and publication preferences. We also collect technical data such as IP address, browser type, and usage analytics to improve our service.</p>',
    ],
    [
        'heading' => 'How We Use Your Information',
        'body'    => '<p>Your data is used to process bookings, communicate order status, deliver publication proofs, provide customer support, and comply with legal obligations. We may send transactional emails and, with your consent, service updates or promotional offers.</p>',
    ],
    [
        'heading' => 'Data Sharing',
        'body'    => '<p>We share necessary booking details with authorized newspaper publishers and payment processors to fulfil your order. We do not sell your personal information to third parties. Data may be disclosed when required by law or to protect our rights and users.</p>',
    ],
    [
        'heading' => 'Security & Retention',
        'body'    => '<p>We use industry-standard encryption and access controls to protect your data. Booking records are retained as required for accounting, dispute resolution, and regulatory compliance, then securely archived or deleted.</p>',
    ],
    [
        'heading' => 'Your Rights',
        'body'    => '<p>You may request access, correction, or deletion of your personal data by contacting us. You can opt out of marketing communications at any time. If you are located in India, you have rights under applicable data protection laws.</p>',
    ],
];
include NAS_DIR . 'templates/partials/legal-page-shell.php';

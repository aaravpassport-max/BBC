<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$legal_eyebrow = 'Legal';
$legal_title   = 'Terms &amp; <span>Conditions</span>';
$legal_intro   = 'Terms governing your use of the ' . $brand . ' newspaper ad booking platform.';
$legal_sections = [
    [
        'heading' => 'Acceptance of Terms',
        'body'    => '<p>By accessing or using our website and booking services, you agree to these Terms &amp; Conditions. If you do not agree, please do not use the platform.</p>',
    ],
    [
        'heading' => 'Booking & Publication',
        'body'    => '<p>All bookings are subject to newspaper publisher approval, material compliance, and availability of the selected edition and date. Rates displayed are indicative until confirmed at checkout. Publication dates may change due to publisher schedules, holidays, or force majeure events.</p>',
    ],
    [
        'heading' => 'Payments',
        'body'    => '<p>Payment must be completed before an ad is submitted to the publisher unless otherwise agreed. All prices are in Indian Rupees (INR) unless stated. GST and applicable taxes are included or shown separately at checkout as required.</p>',
    ],
    [
        'heading' => 'Ad Content Responsibility',
        'body'    => '<p>You are solely responsible for the accuracy and legality of ad content. We reserve the right to reject or modify content that violates publisher guidelines, applicable laws, or our content policy. Defamatory, misleading, or prohibited content will not be accepted.</p>',
    ],
    [
        'heading' => 'Limitation of Liability',
        'body'    => '<p>' . esc_html( $brand ) . ' acts as an intermediary between advertisers and publishers. We are not liable for publisher errors, printing defects, or delays beyond our reasonable control. Our liability is limited to the amount paid for the affected booking.</p>',
    ],
    [
        'heading' => 'Governing Law',
        'body'    => '<p>These terms are governed by the laws of India. Disputes shall be subject to the exclusive jurisdiction of courts in the city where our registered office is located.</p>',
    ],
];
include NAS_DIR . 'templates/partials/legal-page-shell.php';

<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$legal_eyebrow = 'Legal';
$legal_title   = 'Refund <span>Policy</span>';
$legal_intro   = 'Our cancellation and refund guidelines for newspaper ad bookings made through ' . $brand . '.';
$legal_sections = [
    [
        'heading' => 'Cancellation Before Submission',
        'body'    => '<p>If you cancel your booking before the ad is submitted to the newspaper publisher, you are eligible for a full refund minus any payment gateway charges, typically processed within 5–7 business days.</p>',
    ],
    [
        'heading' => 'Cancellation After Submission',
        'body'    => '<p>Once an ad has been submitted to the publisher, cancellation is subject to publisher approval. If approved, a cancellation fee may apply depending on how close the publication date is. Refunds are processed after deducting applicable fees.</p>',
    ],
    [
        'heading' => '72-Hour Rule',
        'body'    => '<p>Cancellations requested more than 72 hours before the scheduled publication date are generally eligible for a full refund. Cancellations within 72 hours may incur a partial deduction to cover processing and publisher costs.</p>',
    ],
    [
        'heading' => 'Non-Refundable Situations',
        'body'    => '<p>Refunds are not available once an ad has been published, for rejected content due to policy violations, or when the client fails to provide required materials within the deadline. Duplicate bookings caused by user error may not qualify for a full refund.</p>',
    ],
    [
        'heading' => 'How to Request a Refund',
        'body'    => '<p>Contact our support team with your Order ID and reason for cancellation. Approved refunds are credited to the original payment method or your wallet balance, as applicable.</p>',
    ],
];
include NAS_DIR . 'templates/partials/legal-page-shell.php';

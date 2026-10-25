<?php

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/15-default-emails.md §15.3, §15.5.
 *
 * Manifest of the Direct Payments default emails, read by DefaultEmailTemplates. Destination when approved:
 * resources/mail/direct_payments/defaults.php (next to the .mjml fragments).
 *
 *   label     — what the admin shows ("Default template: Payment received")
 *   subject   — may use ${variables}; substituted like any handler subject
 *   preview   — inbox preview line (mj-preview), may use ${variables}
 *   always_on — true: sent during a processor change even when nothing is configured (OQ14); can be customised, not
 *               switched off. false: opt-in, sent only when the DP turns the event on with "Default template"
 *   variables — every client variable the template reads; the sender always provides each one (empty when unknown)
 */

$subscription = [
    'firstname', 'lastname', 'email', 'amount', 'currency', 'plan_name', 'direct_payment_name', 'card_brand',
    'card_last4', 'manage_subscription_url', 'site_name', 'site_url', 'access_ends_at', 'next_charge_date',
    'days_until_charge', 'subscription_status',
];
$processorChange = ['renewal_url', 'renewal_date', 'days_left', 'closing_date', 'new_provider_label'];
$receipt = [
    'direct_payments_amount', 'direct_payments_currency', 'direct_payments_card_brand', 'direct_payments_card_last4',
    'direct_payments_payment_method', 'direct_payments_customer_name', 'direct_payments_receipt_url',
    'direct_payments_invoice_number', 'direct_payments_charged_at', 'email', 'site_name', 'site_url',
];

return [

    // ── Processor change (always on) ──────────────────────────────────────────────────────────────────────────
    'payment_details_required' => [
        'label' => 'Payment details required',
        'subject' => 'Action needed: update your payment details for ${plan_name}',
        'preview' => 'Nothing is charged today. Please add your payment details before ${renewal_date}.',
        'always_on' => true,
        'variables' => array_merge($subscription, $processorChange),
    ],
    'payment_details_updated' => [
        'label' => 'Payment details updated',
        'subject' => 'Your payment details are updated',
        'preview' => 'You\'re all set — your subscription continues without a break.',
        'always_on' => true,
        'variables' => array_merge($subscription, $processorChange),
    ],
    'subscription_provider_changed' => [
        'label' => 'Payment provider changed (no action needed)',
        'subject' => 'Nothing to do: a note about your ${plan_name} subscription',
        'preview' => 'Your subscription continues automatically.',
        'always_on' => true,
        'variables' => array_merge($subscription, $processorChange),
    ],
    'subscription_ending_payment_details_required' => [
        'label' => 'Subscription ending — payment details not received',
        'subject' => 'Your ${plan_name} subscription is ending',
        'preview' => 'Update your payment details to keep your subscription.',
        'always_on' => true,
        'variables' => array_merge($subscription, $processorChange),
    ],

    // ── Subscription notifications (opt-in) ───────────────────────────────────────────────────────────────────
    'upcoming_charge' => [
        'label' => 'Upcoming renewal',
        'subject' => 'Your ${plan_name} renews on ${next_charge_date}',
        'preview' => '${amount} on ${next_charge_date}.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'trial_ending' => [
        'label' => 'Trial ending',
        'subject' => 'Your free trial ends on ${next_charge_date}',
        'preview' => 'Your subscription starts on ${next_charge_date}.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'payment_succeeded' => [
        'label' => 'Payment received',
        'subject' => 'Payment received for ${plan_name}',
        'preview' => '${amount} received. Thank you.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'payment_failed' => [
        'label' => 'Payment failed',
        'subject' => 'We couldn\'t process your payment for ${plan_name}',
        'preview' => 'Please check your payment method.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'payment_method_changed' => [
        'label' => 'Payment method changed',
        'subject' => 'Your payment method was updated',
        'preview' => 'Future payments for ${plan_name} use your new payment method.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'subscription_updated' => [
        'label' => 'Plan changed',
        'subject' => 'Your subscription changed to ${plan_name}',
        'preview' => 'Here are the details of your new plan.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'subscription_cancel_scheduled' => [
        'label' => 'Cancellation scheduled',
        'subject' => 'Your ${plan_name} subscription will end on ${access_ends_at}',
        'preview' => 'You keep your access until ${access_ends_at}.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'subscription_canceled' => [
        'label' => 'Subscription cancelled',
        'subject' => 'Your ${plan_name} subscription is cancelled',
        'preview' => 'You won\'t be charged again.',
        'always_on' => false,
        'variables' => $subscription,
    ],
    'subscription_access_ended' => [
        'label' => 'Access ended',
        'subject' => 'Your access to ${plan_name} has ended',
        'preview' => 'Thank you for being with us.',
        'always_on' => false,
        'variables' => $subscription,
    ],

    // ── One-time payments (opt-in) ────────────────────────────────────────────────────────────────────────────
    'payment_receipt' => [
        'label' => 'Payment receipt',
        'subject' => 'Receipt for your payment to ${site_name}',
        'preview' => '${direct_payments_amount} ${direct_payments_currency} — receipt ${direct_payments_invoice_number}.',
        'always_on' => false,
        'variables' => $receipt,
    ],
];

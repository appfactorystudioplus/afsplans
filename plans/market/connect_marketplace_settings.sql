-- ============================================================================
--  Stripe Connect — PROCESSOR profile only
--
--  Scope: this sets up the processor-level Connect profile, which is the
--  template every new connected account is created from. The Direct Payment
--  side (pay_type = CONNECT + its laravel_direct_payment_connect row) is
--  configured through the admin UI, not here.
--
--  Model: buyer pays once -> platform keeps a percentage -> remainder lands
--  in the seller's connected account, with sellers never leaving your site.
--
--  Run against the brain (apps/www) database. Back up first.
-- ============================================================================

-- ── SET THESE ───────────────────────────────────────────────────────────────
SET @processor_ref := 'REPLACE_WITH_PROCESSOR_REFERENCE_NUM';
SET @fee_percent   := 10.00;   -- your cut, percent of the pre-tax line total
SET @ack_by        := 'admin'; -- who is acknowledging API-mode's constraints

-- Find the processor if you don't know its reference:
--   SELECT reference_num, name, provider, mode, is_active
--   FROM laravel_processors WHERE provider = 'stripe';


-- ── The Connect profile ─────────────────────────────────────────────────────
-- JSON_MERGE_PATCH preserves any other keys already in extra_config instead
-- of overwriting the whole column.
UPDATE laravel_processors
SET extra_config = JSON_MERGE_PATCH(
        COALESCE(extra_config, JSON_OBJECT()),
        JSON_OBJECT('connect', JSON_OBJECT(
            'enabled',                TRUE,

            -- White label: no Stripe dashboard for sellers. Requires
            -- losses_collector = application, or Stripe takes over collecting
            -- seller details and the white label is lost.
            'integration_mode',       'self_hosted',

            -- API mode: Stripe renders NOTHING. startOnboarding() returns a
            -- raw requirements list and your own UI must collect every field
            -- and submit it via submitRequirements(). If Stripe ever asks for
            -- proof_of_liveness, the service falls back to a Stripe-hosted
            -- link automatically (liveness_fallback on the handle) -- that one
            -- step cannot be done through the API.
            'onboarding_mode',        'api',

            -- merchant = can take payments; recipient = can RECEIVE transfers.
            -- recipient is mandatory for any marketplace payout.
            'account_configurations', JSON_ARRAY('merchant', 'recipient'),

            -- You pay Stripe's fees and carry chargeback/negative-balance
            -- risk. fees=application is required whenever losses=application.
            'responsibilities',       JSON_OBJECT(
                'fees_collector',   'application',
                'losses_collector', 'application'
            ),

            'default_country',        'US',
            'default_currency',       'usd',

            -- stripe_balance.stripe_transfers is what lets money route to a
            -- connected account at all; .payouts lets it reach their bank.
            'capabilities',           JSON_ARRAY(
                'card_payments',
                'stripe_balance.payouts',
                'stripe_balance.stripe_transfers'
            ),

            -- 'auto' is correct: SplitCalculator::buildSplit() picks the model
            -- per transaction by distinct seller count -- one seller becomes a
            -- destination charge, several become separate transfers.
            'charge_model',           'auto',

            -- Default inherited by Direct Payments that don't override it.
            'platform_fee_percent',   @fee_percent,

            'onboarding_return_path',  '/services/assetstore?connect=return',
            'onboarding_refresh_path', '/services/assetstore?connect=refresh',

            'branding', JSON_OBJECT(
                'icon', NULL, 'logo', NULL,
                'primary_color', NULL, 'secondary_color', NULL
            ),

            -- REQUIRED for onboarding_mode = 'api'. ConnectProfileService
            -- rejects the profile with CONNECT_E_API_MODE_NOT_ACKNOWLEDGED if
            -- either is empty, so the Connect Settings UI would refuse to save
            -- this profile on the next edit if these were left null.
            'api_onboarding_acknowledged_at',
                DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ'),
            'api_onboarding_acknowledged_by', @ack_by
        ))
    )
WHERE reference_num = @processor_ref
  AND provider = 'stripe';


-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT reference_num,
       JSON_PRETTY(JSON_EXTRACT(extra_config, '$.connect')) AS connect_profile
FROM laravel_processors
WHERE reference_num = @processor_ref;

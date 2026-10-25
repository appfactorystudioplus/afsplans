# db_table_alter — www

Raw SQL log for schema changes (edit create migrations in code; log equivalent SQL here).

---

## laravel_forms

```sql
ALTER TABLE `laravel_forms`
    ADD COLUMN `website_domain` VARCHAR(255) NULL AFTER `json_data`,
    ADD COLUMN `website_reference_num` VARCHAR(64) NULL AFTER `website_domain`,
    ADD INDEX `laravel_forms_website_domain_index` (`website_domain`),
    ADD INDEX `laravel_forms_website_reference_num_index` (`website_reference_num`);
```

## laravel_direct_payments

```sql
ALTER TABLE `laravel_direct_payments`
    ADD COLUMN `website_domain` VARCHAR(255) NULL AFTER `pay_type`,
    ADD COLUMN `website_reference_num` VARCHAR(64) NULL AFTER `website_domain`,
    ADD INDEX `laravel_direct_payments_website_domain_index` (`website_domain`),
    ADD INDEX `laravel_direct_payments_website_reference_num_index` (`website_reference_num`);
```

## laravel_sponsorships

```sql
ALTER TABLE `laravel_sponsorships`
    ADD COLUMN `website_domain` VARCHAR(255) NULL AFTER `meta`,
    ADD COLUMN `website_reference_num` VARCHAR(64) NULL AFTER `website_domain`,
    ADD INDEX `laravel_sponsorships_website_domain_index` (`website_domain`),
    ADD INDEX `laravel_sponsorships_website_reference_num_index` (`website_reference_num`);
```

## laravel_appointments

```sql
ALTER TABLE `laravel_appointments`
    ADD COLUMN `website_domain` VARCHAR(255) NULL AFTER `title`,
    ADD COLUMN `website_reference_num` VARCHAR(64) NULL AFTER `website_domain`,
    ADD INDEX `laravel_appointments_website_domain_index` (`website_domain`),
    ADD INDEX `laravel_appointments_website_reference_num_index` (`website_reference_num`);
```

## laravel_ecommerce_stores

```sql
ALTER TABLE `laravel_ecommerce_stores`
    ADD COLUMN `website_domain` VARCHAR(255) NULL AFTER `direct_payment`,
    ADD COLUMN `website_reference_num` VARCHAR(64) NULL AFTER `website_domain`,
    ADD INDEX `laravel_ecommerce_stores_website_domain_index` (`website_domain`),
    ADD INDEX `laravel_ecommerce_stores_website_reference_num_index` (`website_reference_num`);
```

## laravel_events (create)

```sql
CREATE TABLE `laravel_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reference_num` VARCHAR(20) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
    `event_date` DATE NULL,
    `archived` TINYINT NOT NULL DEFAULT 0,
    `direct_payment_reference_num` VARCHAR(20) NULL,
    `payload` JSON NULL,
    `website_domain` VARCHAR(255) NULL,
    `website_reference_num` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `laravel_events_reference_num_unique` (`reference_num`),
    INDEX `laravel_events_website_domain_index` (`website_domain`),
    INDEX `laravel_events_website_reference_num_index` (`website_reference_num`),
    INDEX `laravel_events_event_date_index` (`event_date`)
);
```

## laravel_ecommerce_orders (create)

```sql
CREATE TABLE `laravel_ecommerce_orders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reference_num` VARCHAR(32) NOT NULL,
    `store_reference_num` VARCHAR(32) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `total_amount_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'usd',
    `customer_email` VARCHAR(255) NULL,
    `items_snapshot` JSON NULL,
    `processor_reference_num` VARCHAR(100) NULL,
    `website_domain` VARCHAR(255) NULL,
    `website_reference_num` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `laravel_ecommerce_orders_reference_num_unique` (`reference_num`),
    INDEX `laravel_ecommerce_orders_store_reference_num_index` (`store_reference_num`),
    INDEX `laravel_ecommerce_orders_website_domain_index` (`website_domain`)
);
```

## laravel_analytics_daily_rollups (create)

```sql
CREATE TABLE `laravel_analytics_daily_rollups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `rollup_date` DATE NOT NULL,
    `module` VARCHAR(64) NOT NULL,
    `event` VARCHAR(64) NOT NULL,
    `event_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `website_domain` VARCHAR(255) NULL,
    `website_reference_num` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `analytics_rollups_day_module_event_unique` (`rollup_date`, `module`, `event`, `website_reference_num`),
    INDEX `laravel_analytics_daily_rollups_rollup_date_index` (`rollup_date`),
    INDEX `laravel_analytics_daily_rollups_website_domain_index` (`website_domain`)
);
```

ALTER TABLE `featherpanel_subdomain_manager_domains`
CHANGE `cloudflare_zone_id` `dns_zone_id` VARCHAR(64) DEFAULT NULL,
DROP COLUMN `cloudflare_account_id`;

ALTER TABLE `featherpanel_subdomain_manager_subdomains`
CHANGE `cloudflare_record_id` `dns_record_id` VARCHAR(128) DEFAULT NULL;

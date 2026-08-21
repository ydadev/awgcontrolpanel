ALTER TABLE routing_policy_paths
  ADD COLUMN domain_rules_text MEDIUMTEXT NULL AFTER enabled,
  ADD COLUMN cidr_rules_text MEDIUMTEXT NULL AFTER domain_rules_text;

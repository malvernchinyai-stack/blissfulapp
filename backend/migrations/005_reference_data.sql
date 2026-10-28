-- 005: reference data and default platform settings (editable in /admin)
INSERT INTO instruments (slug, name, sort_order) VALUES
 ('bass-guitar','Bass guitar',10),('electric-guitar','Electric guitar',20),('acoustic-guitar','Acoustic guitar',30),
 ('keyboard','Keyboard',40),('piano','Piano',50),('drums','Drums',60),('percussion','Percussion',70),
 ('saxophone','Saxophone',80),('violin','Violin',90),('trumpet','Trumpet',100),('vocals','Vocals',110),
 ('mbira','Mbira',120),('marimba','Marimba',130),('dj','DJ',140),('sound-engineer','Sound engineer',150);

INSERT INTO genres (slug, name) VALUES
 ('gospel','Gospel'),('jazz','Jazz'),('afro-pop','Afro-pop'),('sungura','Sungura'),('jiti','Jiti'),
 ('chimurenga','Chimurenga'),('classical','Classical'),('rnb-soul','R&B / Soul'),('rock','Rock'),
 ('reggae','Reggae'),('amapiano','Amapiano'),('worship','Contemporary worship');

INSERT INTO settings (name, value_json, updated_at) VALUES
 ('platform_fee_bps', '1000', UTC_TIMESTAMP()),
 ('cancellation_policy', '{"tiers":[{"min_hours_before":72,"charge_bps":0},{"min_hours_before":24,"charge_bps":5000},{"min_hours_before":0,"charge_bps":10000}],"musician_cancel_charge_bps":0}', UTC_TIMESTAMP()),
 ('dispute_window_hours', '48', UTC_TIMESTAMP()),
 ('auto_complete_hours', '48', UTC_TIMESTAMP()),
 ('max_open_offers_per_musician', '25', UTC_TIMESTAMP()),
 ('min_lead_time_hours', '2', UTC_TIMESTAMP()),
 ('supported_currencies', '["USD"]', UTC_TIMESTAMP()),
 ('verification_doc_retention_days', '90', UTC_TIMESTAMP());

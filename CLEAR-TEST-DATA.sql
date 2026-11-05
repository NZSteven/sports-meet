-- Sports Meet 2.0 ONLY. Destructive: all meet records and photos will be erased.
-- Export a full database backup and pause judges/uploads first.
-- Replace EVERY wp_ below with the actual WordPress table prefix.
-- Keep WordPress core tables, sm_settings, users, pages and scoring options.
START TRANSACTION;
DELETE FROM wp_sm_race_entries;
DELETE FROM wp_sm_race_rounds;
DELETE FROM wp_sm_race_members;
DELETE FROM wp_sm_race_relays;
DELETE FROM wp_sm_race_enrollments;
DELETE FROM wp_sm_checkins;
DELETE FROM wp_sm_race_audit;
DELETE FROM wp_sm_relay_members;
DELETE FROM wp_sm_results;
DELETE FROM wp_sm_photos;
DELETE FROM wp_sm_athletes;
DELETE FROM wp_sm_events;
DELETE FROM wp_sm_teams;
INSERT INTO wp_sm_events(name,kind,gender,age_group,direction,unit)
VALUES('8×50米混合接力','relay','X','','asc','秒');
COMMIT;

CREATE TABLE IF NOT EXISTS davvag_events_events (
 id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ownerProfile VARCHAR(100) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 state LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK(JSON_VALID(state)),
 sysversionid BIGINT DEFAULT 0, syscreated INT DEFAULT 0, sysupdated INT DEFAULT 0, sysviewobject INT DEFAULT 0,
 syscreatedby VARCHAR(100), syslastupdatedby VARCHAR(100),
 UNIQUE KEY uq_event_slug(slug), KEY ix_owner(ownerProfile), CHECK(revision>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- STATEMENT --
CREATE TABLE IF NOT EXISTS davvag_events_keys (
 id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 eventId VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind VARCHAR(32) CHARACTER SET ascii NOT NULL,
 scope VARCHAR(32) CHARACTER SET ascii NOT NULL,
 keyValue VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_event_invariant(kind,scope,keyValue), KEY ix_event(eventId),
 CONSTRAINT fk_events_key FOREIGN KEY(eventId) REFERENCES davvag_events_events(id) ON DELETE CASCADE
) ENGINE=InnoDB
-- STATEMENT --
CREATE TABLE IF NOT EXISTS davvag_events_drive_connections (
 id CHAR(64) CHARACTER SET ascii PRIMARY KEY, ownerProfile VARCHAR(100) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 state LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK(JSON_VALID(state)),
 sysversionid BIGINT DEFAULT 0, syscreated INT DEFAULT 0, sysupdated INT DEFAULT 0, sysviewobject INT DEFAULT 0,
 syscreatedby VARCHAR(100), syslastupdatedby VARCHAR(100), UNIQUE KEY uq_drive_owner(ownerProfile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- STATEMENT --
CREATE TABLE IF NOT EXISTS davvag_events_limits (
 id CHAR(64) CHARACTER SET ascii PRIMARY KEY, hits INT UNSIGNED NOT NULL,
 windowEnd BIGINT UNSIGNED NOT NULL, KEY ix_limit_expiry(windowEnd)
) ENGINE=InnoDB
-- STATEMENT --
DROP PROCEDURE IF EXISTS davvag_events_commit_v1
-- STATEMENT --
CREATE PROCEDURE davvag_events_commit_v1(
 IN p_id VARCHAR(32), IN p_expected INT, IN p_slug VARCHAR(100), IN p_owner VARCHAR(100),
 IN p_state LONGTEXT, IN p_keys LONGTEXT, IN p_actor VARCHAR(100)
)
SQL SECURITY INVOKER
commit_block: BEGIN
 DECLARE v_revision INT DEFAULT NULL;
 DECLARE v_owner VARCHAR(100);
 DECLARE v_i INT DEFAULT 0;
 DECLARE v_kind VARCHAR(32);
 DECLARE v_scope VARCHAR(32);
 DECLARE v_value VARCHAR(128);
 DECLARE EXIT HANDLER FOR 1062 BEGIN ROLLBACK; SELECT 'duplicate' AS status, 0 AS revision; END;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 IF NOT JSON_VALID(p_state) OR NOT JSON_VALID(p_keys) OR OCTET_LENGTH(p_state)>900000 OR p_expected<0
    OR JSON_UNQUOTE(JSON_EXTRACT(p_state,'$.id'))<>p_id
    OR JSON_UNQUOTE(JSON_EXTRACT(p_state,'$.ownerProfile'))<>p_owner THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid event commit';
 END IF;
 START TRANSACTION;
 IF p_expected=0 THEN
   INSERT INTO davvag_events_events(id,slug,ownerProfile,revision,state,sysversionid,syscreated,sysupdated,sysviewobject,syscreatedby,syslastupdatedby)
   VALUES(p_id,p_slug,p_owner,1,p_state,DATE_FORMAT(UTC_TIMESTAMP(),'%Y%m%d%H%i%s'),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),0,p_actor,p_actor);
   SET v_revision=1;
 ELSE
   SELECT revision,ownerProfile INTO v_revision,v_owner FROM davvag_events_events WHERE id=p_id FOR UPDATE;
   IF v_revision IS NULL OR v_revision<>p_expected OR v_owner<>p_owner THEN
     ROLLBACK; SELECT 'conflict' AS status, COALESCE(v_revision,0) AS revision; LEAVE commit_block;
   END IF;
   SET v_revision=v_revision+1;
   UPDATE davvag_events_events SET slug=p_slug,revision=v_revision,state=p_state,sysversionid=DATE_FORMAT(UTC_TIMESTAMP(),'%Y%m%d%H%i%s'),sysupdated=UNIX_TIMESTAMP(),syslastupdatedby=p_actor WHERE id=p_id;
 END IF;
 DELETE FROM davvag_events_keys WHERE eventId=p_id;
 WHILE v_i<JSON_LENGTH(p_keys) DO
   SET v_kind=JSON_UNQUOTE(JSON_EXTRACT(p_keys,CONCAT('$[',v_i,'].kind')));
   SET v_scope=JSON_UNQUOTE(JSON_EXTRACT(p_keys,CONCAT('$[',v_i,'].scope')));
   SET v_value=JSON_UNQUOTE(JSON_EXTRACT(p_keys,CONCAT('$[',v_i,'].value')));
   IF v_scope<>p_id AND v_scope<>'tenant' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid invariant scope'; END IF;
   INSERT INTO davvag_events_keys(id,eventId,kind,scope,keyValue)
     VALUES(SHA2(CONCAT(v_kind,'|',v_scope,'|',v_value),256),p_id,v_kind,v_scope,v_value);
   SET v_i=v_i+1;
 END WHILE;
 COMMIT;
 SELECT 'ok' AS status,v_revision AS revision;
END
-- STATEMENT --
DROP PROCEDURE IF EXISTS davvag_events_connection_commit_v1
-- STATEMENT --
CREATE PROCEDURE davvag_events_connection_commit_v1(IN p_id CHAR(64), IN p_owner VARCHAR(100), IN p_expected INT, IN p_state LONGTEXT)
SQL SECURITY INVOKER
connection_block: BEGIN
 DECLARE v_revision INT DEFAULT NULL;
 DECLARE EXIT HANDLER FOR 1062 BEGIN ROLLBACK; SELECT 'conflict' AS status,0 AS revision; END;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 IF NOT JSON_VALID(p_state) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid connection'; END IF;
 START TRANSACTION;
 IF p_expected=0 THEN
   INSERT INTO davvag_events_drive_connections(id,ownerProfile,revision,state,syscreated,sysupdated,sysviewobject) VALUES(p_id,p_owner,1,p_state,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),0);
   SET v_revision=1;
 ELSE
   SELECT revision INTO v_revision FROM davvag_events_drive_connections WHERE id=p_id AND ownerProfile=p_owner FOR UPDATE;
   IF v_revision IS NULL OR v_revision<>p_expected THEN ROLLBACK;SELECT 'conflict' AS status,COALESCE(v_revision,0) AS revision;LEAVE connection_block;END IF;
   SET v_revision=v_revision+1;
   UPDATE davvag_events_drive_connections SET state=p_state,revision=v_revision,sysupdated=UNIX_TIMESTAMP() WHERE id=p_id;
 END IF;
 COMMIT;SELECT 'ok' AS status,v_revision AS revision;
END
-- STATEMENT --
DROP PROCEDURE IF EXISTS davvag_events_rate_v1
-- STATEMENT --
CREATE PROCEDURE davvag_events_rate_v1(IN p_key CHAR(64), IN p_max INT, IN p_window INT)
SQL SECURITY INVOKER
BEGIN
 DECLARE v_hits INT;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 INSERT INTO davvag_events_limits(id,hits,windowEnd) VALUES(p_key,1,UNIX_TIMESTAMP()+p_window)
 ON DUPLICATE KEY UPDATE hits=IF(windowEnd<UNIX_TIMESTAMP(),1,hits+1),windowEnd=IF(windowEnd<UNIX_TIMESTAMP(),UNIX_TIMESTAMP()+p_window,windowEnd);
 SELECT hits INTO v_hits FROM davvag_events_limits WHERE id=p_key FOR UPDATE;
 COMMIT;SELECT IF(v_hits<=p_max,'ok','limited') AS status,0 AS revision;
END

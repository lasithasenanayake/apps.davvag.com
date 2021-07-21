CREATE TABLE `products_reviews` (
  `itemid` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `profileid` int(11) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `comment` mediumtext CHARACTER SET utf8,
  `sysversionid` mediumtext NOT NULL,
  PRIMARY KEY (`itemid`,`pid`)
) 

DELIMITER $$ 


CREATE TABLE `profile_followers` (
  `id` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `sysversionid` mediumtext NOT NULL,
  PRIMARY KEY (`id`,`pid`)
) 
DELIMITER $$ 

CREATE TABLE `profile_tags` (
  `uid` varchar(50) NOT NULL,
  `id` int(11) DEFAULT NULL,
  `tag` mediumtext CHARACTER SET utf8,
  `sysversionid` mediumtext NOT NULL,
  PRIMARY KEY (`uid`)
) 
DELIMITER $$ 
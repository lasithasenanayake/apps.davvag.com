
DROP procedure IF EXISTS `products_stelup`;





DELIMITER $$

CREATE PROCEDURE products_stelup
(
    param_page INT,
    param_size INT,
    q text,
    p_id INT
)
BEGIN
	if q<>"" then
		SELECT *,(select count(0) from products_likes  where itemid=p.itemid and pid=p_id) as liked,(select count(0) from products_favorites where itemid=p.itemid and pid=p_id)  as favorite,  MATCH(p.name, p.caption,p.keywords) AGAINST(q) AS score From products p where showonstore='y' and MATCH(p.name, p.caption,p.keywords) AGAINST(q) Limit param_page, param_size;
	else
        SELECT *,(select count(0) from products_likes  where itemid=p.itemid and pid=p_id) as liked,(select count(0) from products_favorites where itemid=p.itemid and pid=p_id)  as favorite From products p where showonstore='y' order by p.sysversionid desc Limit param_page, param_size;
	end if;
END$$

DELIMITER ;


DROP procedure IF EXISTS `products_stelup_2`;





DELIMITER $$

CREATE PROCEDURE products_stelup_2
(
    param_page INT,
    param_size INT,
    tid INT,
    p_id INT,
    r_id INT
)
BEGIN
	if tid=3 then
		SELECT *,(select count(0) from products_likes  where itemid=p.itemid and pid=p_id) as liked,(select count(0) from products_favorites where itemid=p.itemid and pid=p_id)  as favorite From products p inner join products_attributes on p.itemid= products_attributes.itemid where showonstore='y' and p.itemid in(select itemid from products_likes where pid=r_id) Limit param_page, param_size;
	elseif tid=2 then
        SELECT *,(select count(0) from products_likes  where itemid=p.itemid and pid=p_id) as liked,(select count(0) from products_favorites where itemid=p.itemid and pid=p_id)  as favorite From products p inner join products_attributes on p.itemid= products_attributes.itemid where showonstore='y' and p.itemid in(select itemid from products_favorites where pid=r_id) order by p.sysversionid desc Limit param_page, param_size;
	else
        SELECT *,(select count(0) from products_likes  where itemid=p.itemid and pid=p_id) as liked,(select count(0) from products_favorites where itemid=p.itemid and pid=p_id)  as favorite From products p inner join products_attributes on p.itemid= products_attributes.itemid where showonstore='y' and p.storeid=r_id Limit param_page, param_size;
    end if;
END$$

DELIMITER ;


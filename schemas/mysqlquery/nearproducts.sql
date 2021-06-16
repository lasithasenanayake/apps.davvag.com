DROP procedure IF EXISTS `getnearproducts`;

DELIMITER $$

CREATE PROCEDURE getnearproducts
(
    param_lat DECIMAL(10, 8),
    param_lng DECIMAL(11, 8),
    param_catid varchar(100),
    param_page INT,
    param_size INT,
    q text,
    param_radius INT
)
BEGIN
DECLARE var_lan1 decimal(11,8);
DECLARE var_lan2 decimal(11,8);
DECLARE var_lat1 decimal(10,8);
DECLARE var_lat2 decimal(10,8);
SET var_lan1 =param_lng-(1/111*param_radius);
SET var_lan2 =param_lng+(1/111*param_radius);
SET var_lat1 =param_lat-(1/111*param_radius);
SET var_lat2 =param_lat+(1/111*param_radius);
IF param_lat<>0 THEN
    SELECT * FROM ((product_published INNER JOIN storeproductmapping ON products.itemid=storeproductmapping.productid) INNER JOIN store ON store.id = storeproductmapping.Storeid) where store.latitude between var_lat1 and var_lat2 and store.longitude between var_lan1 and var_lan2 AND products.catogory=param_catid;
ELSE
	if q<>"" then
		SELECT *,  MATCH(name, caption,keywords) AGAINST(q) AS score From products where showonstore='y' and MATCH(name, caption,keywords) AGAINST(q) Limit param_page, param_size;
	else
        IF param_catid <>"" THEN 
		    SELECT * From products where showonstore='y' and catogory=param_catid Limit param_page, param_size;
        ELSE
            SELECT * From products where showonstore='y'  Limit param_page, param_size;
        end if;
	end if;
END IF;


END

DELIMITER $$

ALTER TABLE products
ADD FULLTEXT INDEX `index2` (`name` ASC, `caption` ASC, `keywords` ASC);

DELIMITER $$
ALTER TABLE products
ADD FULLTEXT INDEX `index2` (`name` ASC, `caption` ASC, `keywords` ASC);

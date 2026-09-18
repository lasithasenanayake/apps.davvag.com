<?php
namespace lesson_market_place;
require_once __DIR__ . '/MarketplaceRules.php';

final class MarketplaceSchema
{
    public static function namespaces()
    {
        return ['lmp_package','lmp_version','lmp_version_lesson','lmp_enrolment','lmp_attempt','lmp_grant','lmp_operation','lmp_audit','course_manager_enrollment'];
    }

    /** Must run BEFORE entering any ledger transaction. Never creates business data. */
    public static function ensure($db)
    {
        foreach (['course_manager_notification','lesson_manager_lesson','course_manager_subject','course_manager_course','course_manager_classgrade','profile'] as $dependency) {
            $result=\SOSSData::WithServiceNamespaces([$dependency],function()use($dependency){return \SOSSData::Query($dependency,'',null,'asc',1,0,null,false);});
            if (!$result || !$result->success) throw new MarketplaceException('Required learning schemas could not be initialized.');
        }
        foreach (self::namespaces() as $namespace) {
            $result = \SOSSData::Query($namespace, '', null, 'asc', 1, 0, null, false);
            if (!$result || !$result->success) throw new MarketplaceException('Marketplace schema initialization failed. Ask an administrator to run the migration.');
            $schema = json_decode(file_get_contents(SCHEMA_PATH . '/' . $namespace . '.json'));
            self::columns($db,$namespace,$schema);
            foreach (($schema->indexes ?? []) as $index) {
                $existing = $db->all('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? ORDER BY SEQ_IN_INDEX', 'ss', [$namespace,$index->name]);
                if ($existing) {
                    $columns = array_map(function($row){return $row->COLUMN_NAME;}, $existing);
                    if ($columns !== $index->columns || (bool)$existing[0]->NON_UNIQUE === (bool)$index->unique) throw new MarketplaceException('Marketplace index definition differs from the installed contract. Migration requires administrator review.');
                    continue;
                }
                // Identifiers originate only from the protected tenant schema, never HTTP input.
                foreach (array_merge([$namespace,$index->name],$index->columns) as $identifier) if (!preg_match('/^[a-z0-9_]+$/D',$identifier)) throw new MarketplaceException('Invalid marketplace schema index.');
                $db->run('CREATE ' . ($index->unique ? 'UNIQUE ' : '') . 'INDEX `' . $index->name . '` ON `' . $namespace . '` (`' . implode('`,`',$index->columns) . '`)');
            }
            $engine = $db->one('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?','s',[$namespace]);
            if (!$engine || strtolower($engine->ENGINE) !== 'innodb') throw new MarketplaceException('Marketplace tables must use InnoDB for atomic enrolment.');
        }
    }

    private static function columns($db,$namespace,$schema)
    {
        // Add nullable fields and widen strings only. Never infer values for historical business rows.
        foreach ($schema->fields as $field) {
            $name=$field->fieldName;
            if(!preg_match('/^[a-z0-9_]+$/D',$name))throw new MarketplaceException('Invalid migration field.');
            $column=$db->one('SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?','ss',[$namespace,$name]);
            $type=$field->dataType;
            $length=(int)($field->annotations->maxLen ?? 0);
            $sql=$type==='int'?'INT':($type==='java.util.Date'?'DATETIME':(!empty($field->annotations->encoding)?'MEDIUMTEXT':($length?'VARCHAR('.$length.')':'TEXT')));
            if(!$column) {
                if(!empty($field->annotations->isPrimary))throw new MarketplaceException('An existing marketplace table lacks its primary key. Administrator migration is required.');
                $db->run('ALTER TABLE `'.$namespace.'` ADD COLUMN `'.$name.'` '.$sql.' NULL');
            } elseif($type==='java.lang.String') {
                $actual=strtolower($column->DATA_TYPE);
                if(!in_array($actual,['varchar','text','mediumtext','longtext'],true))throw new MarketplaceException('An installed marketplace field has an incompatible type.');
                if(($sql==='MEDIUMTEXT' && in_array($actual,['varchar','text'],true)) || ($actual==='varchar' && $length>(int)$column->CHARACTER_MAXIMUM_LENGTH))$db->run('ALTER TABLE `'.$namespace.'` MODIFY COLUMN `'.$name.'` '.$sql.' NULL');
            } elseif(($type==='int'&&!in_array(strtolower($column->DATA_TYPE),['int','bigint'],true)) || ($type==='java.util.Date'&&!in_array(strtolower($column->DATA_TYPE),['datetime','timestamp'],true)))throw new MarketplaceException('An installed marketplace field has an incompatible type.');
        }
    }
}

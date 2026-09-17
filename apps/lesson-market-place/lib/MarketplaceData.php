<?php
namespace lesson_market_place;

require_once PLUGIN_PATH . '/sossdata/SOSSData.php';
require_once __DIR__ . '/MarketplaceRules.php';

/**
 * Schema-aware persistence boundary for Lesson Marketplace.
 *
 * Ordinary application reads and writes must pass through SOSSData so the
 * active tenant connector, schema validation, query firewall, service-only
 * namespace checks, and view-object filtering remain in force.
 */
final class MarketplaceData
{
    public function query($namespace, array $conditions = [], array $sorting = [], $limit = 100, $offset = 0, $viewObject = true)
    {
        $query = [
            'conditions' => array_values($conditions),
            'pageSize' => (int)$limit,
            'pageFrom' => (int)$offset
        ];
        if ($sorting) {
            $query['sorting'] = array_values($sorting);
        }

        $result = \SOSSData::Query($namespace, $query, null, 'DESC', $query['pageSize'], $query['pageFrom'], null, $viewObject);

        return $this->requireSuccess($result, 'The requested records could not be loaded.');
    }

    public function rows($namespace, array $conditions = [], array $sorting = [], $limit = 100, $offset = 0, $viewObject = true)
    {
        return $this->query($namespace, $conditions, $sorting, $limit, $offset, $viewObject)->result ?: [];
    }

    public function one($namespace, array $conditions, array $sorting = [], $viewObject = true)
    {
        $rows = $this->rows($namespace, $conditions, $sorting, 1, 0, $viewObject);
        return $rows ? $rows[0] : null;
    }

    public function byId($namespace, $id, $key = 'id', $viewObject = true)
    {
        return $this->one($namespace, [
            ['column' => $key, 'operator' => '=', 'value' => (int)$id]
        ], [], $viewObject);
    }

    public function insert($namespace, $values)
    {
        $record = is_object($values) ? clone $values : (object)$values;
        $result = \SOSSData::Insert($namespace, $record);
        $result = $this->requireSuccess($result, 'The record could not be created.');
        if (!isset($result->result->generatedId)) {
            throw new MarketplaceException('The datastore did not return the new record identifier.');
        }
        return (int)$result->result->generatedId;
    }

    public function update($namespace, $record, array $changes = [])
    {
        if (!$record || !isset($record->id)) {
            throw new MarketplaceException('The record to update was not found.');
        }
        // Send only the primary key and changed fields. Besides avoiding stale
        // overwrites, this prevents nullable dates returned by an adapter from
        // being normalized into unintended values during an unrelated update.
        $save = (object)['id' => (int)$record->id];
        foreach ($changes as $field => $value) {
            $save->$field = $value;
        }
        $result = \SOSSData::Update($namespace, $save);
        $this->requireSuccess($result, 'The record could not be updated.');
        return $save;
    }

    public function updateById($namespace, $id, array $changes, $viewObject = true)
    {
        return $this->update($namespace, $this->byId($namespace, $id, 'id', $viewObject), $changes);
    }

    private function requireSuccess($result, $message)
    {
        if (!$result || empty($result->success)) {
            throw new MarketplaceException($message);
        }
        return $result;
    }
}

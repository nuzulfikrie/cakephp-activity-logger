<?php

namespace Elastic\ActivityLogger\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Utility\Hash;
use \ArrayObject;
use Psr\Log\LogLevel;
use Elastic\ActivityLogger\Model\Entity\ActivityLog;
use Elastic\ActivityLogger\Model\Table\ActivityLogsTable;

/**
 * Logger behavior
 *
 * example:
 *
 * in Table (eg. CommentsTable)
 * <pre><code>
 * public function initialize(array $config)
 * {
 *      $this->addBehavior('Elastic/ActivityLogger.Logger', [
 *          'scope' => [
 *              'Elastic/ActivityLogger.Authors',
 *              'Elastic/ActivityLogger.Articles',
 *              'Elastic/ActivityLogger.Users',
 *          ],
 *      ]);
 * }
 * </code></pre>
 *
 * set Scope/Issuer
 * <pre><code>
 * $commentsTable->logScope([$artice, $author])->logIssuer($user);
 * </code></pre>
 */
class LoggerBehavior extends Behavior
{

    /**
     * Default configuration.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'logTable' => 'Elastic/ActivityLogger.ActivityLogs',
        'scope'    => [],
    ];

    public function implementedEvents()
    {
        return parent::implementedEvents() + [
            'Model.initialize' => 'afterInit',
        ];
    }

    public function implementedMethods()
    {
        return parent::implementedMethods() + [
            'activityLog' => 'log',
        ];
    }

    /**
     * Table.initializeの後に実行
     *
     * @param Event $event
     */
    public function afterInit(Event $event)
    {
        $scope = $this->config('scope');
        if (empty($scope)) {
            $scope = [$this->_table->registryAlias()];
        }
        $this->config('scope', $scope, false);
        $this->config('originalScope', $scope);
    }

    public function afterSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $log = $this->buildLog($entity, $this->config('issuer'));
        $log->action = $entity->isNew() ? ActivityLog::ACTION_CREATE : ActivityLog::ACTION_UPDATE;
        $log->data = $this->getDirtyData($entity);
        $log->message = $this->buildMessage($log, $entity, $this->config('issuer'));

        $logs = $this->duplicateLogByScope($this->config('scope'), $log, $entity);

        $this->saveLogs($logs);
    }

    public function afterDelete(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $log = $this->buildLog($entity, $this->config('issuer'));
        $log->action = ActivityLog::ACTION_DELETE;
        $log->data = $this->getData($entity);
        $log->message = $this->buildMessage($log, $entity, $this->config('issuer'));

        $logs = $this->duplicateLogByScope($this->config('scope'), $log, $entity);

        $this->saveLogs($logs);
    }

    /**
     * ログスコープの設定
     *
     * @param mixed $args if $args === false リセット
     * @return Table
     */
    public function logScope($args = null)
    {
        if (is_null($args)) {
            // getter
            return $this->config('scope');
        }

        if ($args === false) {
            // reset
            $this->config('scope', $this->config('originalScope'), false);
        } else {
            // setter
            $this->config('scope', $args);
        }
        return $this->_table;
    }

    /**
     * ログ発行者の設定
     *
     * @param \Cake\ORM\Entity $issuer
     * @return Table
     */
    public function logIssuer(\Cake\ORM\Entity $issuer = null)
    {
        if (is_null($issuer)) {
            // getter
            return $this->config('issuer');
        }
        // setter
        $this->config('issuer', $issuer);

        // scopeに含む場合、併せてscopeにセット
        list($issuerModel, $issuerId) = $this->buildObjectParameter($this->config('issuer'));
        if (in_array($issuerModel, array_keys($this->config('scope')))) {
            $this->logScope($issuer);
        }
        return $this->_table;
    }

    /**
     * メッセージ生成メソッドの設定
     *
     * @param \Elastic\ActivityLogger\Model\Behavior\callable $handler
     * @return callable
     */
    public function logMessageBuilder(callable $handler = null)
    {
        if (is_null($handler)) {
            // getter
            return $this->config('messageBuilder');
        }
        // setter
        $this->config('messageBuilder', $handler);
    }

    /**
     * カスタムログの記述
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = [])
    {
        $entity = !empty($context['object']) ? $context['object'] : null;
        $issuer = !empty($context['issuer']) ? $context['issuer'] : $this->config('issuer');
        $scope = !empty($context['scope']) ? $this->__configScope($context['scope']) : $this->config('scope');

        $log = $this->buildLog($entity, $issuer);
        $log->action = isset($context['action']) ? $context['action'] : ActivityLog::ACTION_RUNTIME;
        $log->data = isset($context['data']) ? $context['data'] : $this->getData($entity);

        $log->level = $level;
        $log->message = $message;
        $log->message = $this->buildMessage($log, $entity, $issuer);

        // issuerをscopeに含む場合、併せてscopeにセット
        if (!empty($log->issuer_id) && in_array($log->issuer_model, array_keys($this->config('scope')))) {
            $scope[$log->issuer_model] = $log->issuer_id;
        }

        $logs = $this->duplicateLogByScope($scope, $log, $entity);

        $this->saveLogs($logs);
        return $logs;
    }

    /**
     * アクティビティログの取得
     *
     * $table->find('activity', ['scope' => $entity])
     *
     * @param \Cake\ORM\Query $query
     * @param array $options
     * @return \Cake\ORM\Query
     */
    public function findActivity(\Cake\ORM\Query $query, array $options)
    {
        $logTable = $this->getLogTable();
        $query = $logTable->find();

        $where = [$logTable->aliasField('scope_model') => $this->_table->registryAlias()];

        if (isset($options['scope']) && $options['scope'] instanceof \Cake\ORM\Entity) {
            list($scopeModel, $scopeId) = $this->buildObjectParameter($options['scope']);
            $where[$logTable->aliasField('scope_model')] = $scopeModel;
            $where[$logTable->aliasField('scope_id')] = $scopeId;
        }

        $query->where($where)->order([$logTable->aliasField('id') => 'desc']);

        return $query;
    }

    /**
     * ログを作成
     *
     * @param EntityInterface $entity
     * @param EntityInterface $issuer
     * @return ActivityLog
     */
    private function buildLog(EntityInterface $entity = null, EntityInterface $issuer = null)
    {
        list($issuer_model, $issuer_id) = $this->buildObjectParameter($issuer);
        list($object_model, $object_id) = $this->buildObjectParameter($entity);

        $level = LogLevel::INFO;
        $message = '';

        $logTable = $this->getLogTable();
        /* @var \Elastic\ActivityLogger\Model\Table\ActivityLogsTable $logTable */
        $log = $logTable->newEntity(compact('issuer_model', 'issuer_id', 'object_model', 'object_id', 'level', 'message'));
        return $log;
    }

    /**
     * エンティティからパラメータの取得
     *
     * @param \Cake\ORM\Entity $object
     * @return array [object_model, object_id]
     */
    private function buildObjectParameter($object)
    {
        $objectModel = null;
        $objectId = null;
        if ($object && $object instanceof \Cake\ORM\Entity) {
            $objectTable = TableRegistry::get($object->source());
            $objectModel = $objectTable->registryAlias();
            $objectId = $object->get($objectTable->primaryKey());
        }
        return [$objectModel, $objectId];
    }

    /**
     * メッセージの生成
     *
     * @param ActivityLog $log
     * @param EntityInterface $entity
     * @param EntityInterface $issuer
     * @return string
     */
    private function buildMessage($log, $entity = null, $issuer = null)
    {
        if (!is_callable($this->config('messageBuilder'))) {
            return $log->message;
        }
        $context = ['object' => $entity, 'issuer' => $issuer];
        return call_user_func($this->config('messageBuilder'), $log, $context);
    }

    /**
     * ログデータをスコープに応じて複製
     *
     * @param array $scope
     * @param ActivityLog $log
     * @param EntityInterface $entity
     * @return ActivityLog[]
     */
    private function duplicateLogByScope(array $scope, ActivityLog $log, EntityInterface $entity = null)
    {
        $logs = [];
        foreach ($scope as $scopeModel => $scopeId) {
            if (!empty($entity) && $scopeModel === $this->_table->registryAlias()) {
                // モデル自身に対する更新の場合は、entityのidをセットする
                $scopeId = $entity->get($this->_table->primaryKey());
            }
            if (empty($scopeId)) {
                continue;
            }
            $new = $this->getLogTable()->newEntity($log->toArray() + [
                'scope_model' => $scopeModel,
                'scope_id'    => $scopeId,
            ]);
            $logs[] = $new;
        }
        return $logs;
    }

    /**
     *
     * @param ActivityLog[] $logs
     */
    private function saveLogs($logs)
    {
        $logTable = $this->getLogTable();
        /* @var \Elastic\ActivityLogger\Model\Table\ActivityLogsTable $logTable */
        $logTable->connection()->useSavePoints(true);
        return $logTable->connection()->transactional(function () use ($logTable, $logs) {
            foreach ($logs as $log) {
                $logTable->save($log, ['atomic' => false]);
            }
        });
    }

    /**
     *
     * @return \Elastic\ActivityLogger\Model\Table\ActivityLogsTable
     */
    private function getLogTable()
    {
        return TableRegistry::get('ActivityLog', [
            'className' => $this->config('logTable'),
        ]);
    }

    /**
     * エンティティ変更値の取得
     *
     * hiddenに設定されたものは除く
     *
     * @param EntityInterface $entity
     * @return array
     */
    private function getDirtyData(EntityInterface $entity = null)
    {
        if (empty($entity)) {
            return null;
        }
        return $entity->extract($entity->visibleProperties(), true);
    }

    /**
     * エンティティ値の取得
     *
     * hiddenに設定されたものは除く
     *
     * @param EntityInterface $entity
     * @return array
     */
    private function getData(EntityInterface $entity = null)
    {
        if (empty($entity)) {
            return null;
        }
        return $entity->extract($entity->visibleProperties());
    }

    protected function _configWrite($key, $value, $merge = false)
    {
        if ($key === 'scope') {
            $value = $this->__configScope($value);
        }
        parent::_configWrite($key, $value, $merge);
    }

    /**
     * scope設定
     *
     * @param mixed $value
     * @return array
     */
    private function __configScope($value)
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $new = [];
        foreach ($value as $arg) {
            if (is_string($arg)) {
                $new[$arg] = null;
            } elseif ($arg instanceof \Cake\ORM\Entity) {
                $table = TableRegistry::get($arg->source());
                $new[$table->registryAlias()] = $arg->get($table->primaryKey());
            }
        }

        return $new;
    }
}

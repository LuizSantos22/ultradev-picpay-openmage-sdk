<?php
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$table = $installer->getTable('sales/order');
$connection = $installer->getConnection();

if (!$connection->tableColumnExists($table, 'picpay_charge_id')) {
    $connection->addColumn($table, 'picpay_charge_id', [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 64,
        'nullable' => true,
        'comment'  => 'PicPay Charge Id',
    ]);
    $connection->addIndex(
        $table,
        $installer->getIdxName('sales/order', ['picpay_charge_id']),
        ['picpay_charge_id']
    );
}

$installer->endSetup();

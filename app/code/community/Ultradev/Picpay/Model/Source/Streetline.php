<?php
class Ultradev_Picpay_Model_Source_Streetline
{
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => 'Não usar'],
            ['value' => 1, 'label' => 'Linha 1'],
            ['value' => 2, 'label' => 'Linha 2'],
            ['value' => 3, 'label' => 'Linha 3'],
            ['value' => 4, 'label' => 'Linha 4'],
        ];
    }
}

<?php

namespace App\Enums;

enum InventoryReason: string
{
    case OrderPlaced = 'order_placed';
    case OrderCancelled = 'order_cancelled';
    case ManualAdjustment = 'manual_adjustment';
    case Restock = 'restock';
    case Initial = 'initial';
}

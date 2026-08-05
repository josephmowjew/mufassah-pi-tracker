<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inventory action approval recipient
    |--------------------------------------------------------------------------
    |
    | PINs for administrative sales and device deletions are delivered to this
    | protected administrator mailbox. The initiating user must still be an
    | authorized administrator with a verified account email.
    |
    */
    'approval_email' => env('INVENTORY_ACTION_APPROVAL_EMAIL', 'elijahpchitedze@gmail.com'),

];

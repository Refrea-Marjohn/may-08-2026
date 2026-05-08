<?php
/**
 * Office/School Assignment options for SDO CABUYAO (dropdowns in apply loan / edit loan).
 * Used so users select from list instead of free text.
 */
if (!isset($office_school_options)) {
    $office_school_options = [
        'ELEMENTARY' => [
            'BACLARAN ELEMENTARY SCHOOL',
            'BANAY-BANAY ELEMENTARY SCHOOL',
            'BANLIC ELEMENTARY SCHOOL',
            'BIGAA ELEMENTARY SCHOOL',
            'BUTONG ELEMENTARY SCHOOL',
            'CABUYAO CENTRAL ELEMENTARY SCHOOL',
            'CASILE ELEMENTARY SCHOOL',
            'DIEZMO ELEMENTARY SCHOOL',
            'GUINTING ELEMENTARY SCHOOL',
            'GULOD ELEMENTARY SCHOOL',
            'MAMATID ELEMENTARY SCHOOL',
            'MARINIG SOUTH ELEMENTARY SCHOOL',
            'NIUGAN ELEMENTARY SCHOOL',
            'NORTH MARINIG ELEMENTARY SCHOOL',
            'PULO ELEMENTARY SCHOOL',
            'SALA ELEMENTARY SCHOOL',
            'SAN ISIDRO ELEMENTARY SCHOOL',
            'SOUTHVILLE I ELEMENTARY SCHOOL',
            'PITTLAND ELEMENTARY SCHOOL',
        ],
        'JUNIOR HS' => [
            'MARINIG NATIONAL HIGH SCHOOL',
            'CASILE INTEGRATED NATIONAL HIGH SCHOOL',
            'DIEZMO INTEGRATED SCHOOL',
            'MAMATID NATIONAL HIGH SCHOOL',
        ],
        'SENIOR HS' => [
            'CASILE INTEGRATED NATIONAL HIGH SCHOOL',
            'MAMATID SENIOR HIGH SCHOOL',
            'PULO SENIOR HIGH SCHOOL',
        ],
        'SDO CABUYAO' => [
            'CID',
            'SGOD',
            'ASDS',
            'OSDS',
            'SGOD - HEALTH SECTION UNIT',
            'OSDS - ACCOUNTING UNIT',
            'OSDS - ADMIN UNIT',
            'OSDS - RECORDS UNIT',
            'OSDS - PERSONNEL UNIT',
            'OSDS - LEGAL UNIT',
            'OSDS - BUDGET UNIT',
            'OSDS - CASHIER UNIT',
            'OSDS - PROCUREMENT UNIT',
            'OSDS - SUPPLY UNIT',
            'OSDS - ICT UNIT',
        ],
    ];
}

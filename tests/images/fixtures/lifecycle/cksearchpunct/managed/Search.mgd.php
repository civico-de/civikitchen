<?php
// {, } is conditional punctuation; core resolves it before Smarty compiles.
return [
  [
    'name' => 'SavedSearch_cksearchpunct',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchpunct',
        'label' => 'cksearchpunct',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id', 'display_name', 'contact_type']],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_cksearchpunct',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchpunct',
        'label' => 'cksearchpunct',
        'saved_search_id.name' => 'cksearchpunct',
        'type' => 'table',
        'settings' => ['columns' => [
          ['type' => 'field', 'key' => 'display_name', 'rewrite' => '[id]{, }[display_name]'],
        ]],
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];

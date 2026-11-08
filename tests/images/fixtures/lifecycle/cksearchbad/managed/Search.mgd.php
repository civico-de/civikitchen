<?php
// One column compiles but its tally has an unclosed {if}; the other column has one too.
return [
  [
    'name' => 'SavedSearch_cksearchbad',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchbad',
        'label' => 'cksearchbad',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id', 'display_name', 'contact_type']],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_cksearchbad',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchbad',
        'label' => 'cksearchbad',
        'saved_search_id.name' => 'cksearchbad',
        'type' => 'table',
        'settings' => ['columns' => [
          ['type' => 'field', 'key' => 'display_name', 'rewrite' => '{if [id] > 0}fine{/if}', 'tally' => ['fn' => 'COUNT', 'rewrite' => '{if [display_name] > 0}unclosed']],
          ['type' => 'field', 'key' => 'id', 'rewrite' => '{if [id] > 0}unclosed'],
        ]],
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];

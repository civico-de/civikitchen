<?php
// Square tokens beside Smarty; core replaces the tokens first.
return [
  [
    'name' => 'SavedSearch_cksearchgood',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchgood',
        'label' => 'cksearchgood',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id', 'display_name', 'contact_type']],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_cksearchgood',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'cksearchgood',
        'label' => 'cksearchgood',
        'saved_search_id.name' => 'cksearchgood',
        'type' => 'table',
        'settings' => ['columns' => [
          ['type' => 'field', 'key' => 'id', 'rewrite' => '{if [id] > 0}<a href="{crmURL p=\'civicrm/contact/view\' q=\'cid=[id]\'}">{ts}Open{/ts}</a>{/if}'],
          ['type' => 'field', 'key' => 'display_name', 'rewrite' => '{if "[display_name]"}[display_name]{/if}'],
          ['type' => 'field', 'key' => 'contact_type', 'rewrite' => '[contact_type]', 'tally' => ['fn' => 'COUNT', 'rewrite' => '{if [contact_type] > 0}[contact_type]{/if}']],
        ]],
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];

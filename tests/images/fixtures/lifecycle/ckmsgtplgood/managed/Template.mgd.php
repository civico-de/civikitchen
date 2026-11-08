<?php
// Civi tokens beside Smarty, as a real body mixes them; core substitutes the tokens first.
return [
  [
    'name' => 'MessageTemplate_ckmsgtplgood',
    'entity' => 'MessageTemplate',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'ckmsgtplgood',
        'msg_subject' => 'Hello {contact.first_name}',
        'msg_text' => '{contact.display_name|upper}{if $note} {$note}{/if}{if {contact.id} > 0} known{/if}',
        'msg_html' => '<p>{contact.display_name}</p><a href="{action.optOutUrl}">{ts}Opt out{/ts}</a>',
      ],
      'match' => ['msg_title'],
    ],
  ],
];

<?php
// A SearchKit table of the mailing lists with their subscriber counts, shown
// as an Afform page at civicrm/demo/mailing-lists.

require_once __DIR__ . '/../../lib.php';

ck_seed_search_page('DemoMailingLists', 'Verteiler', 'Group', [
  'select' => ['title', 'COUNT(Group_GroupContact_group_id_01.id) AS COUNT_Group_GroupContact_group_id_01_id'],
  'join' => [['GroupContact AS Group_GroupContact_group_id_01', 'LEFT', ['id', '=', 'Group_GroupContact_group_id_01.group_id'], ['Group_GroupContact_group_id_01.status', '=', '"Added"']]],
  'where' => [['group_type:name', 'CONTAINS', 'Mailing List']],
  'groupBy' => ['id'],
  'orderBy' => ['title' => 'ASC'],
], [
  'title' => 'Verteiler',
  'COUNT_Group_GroupContact_group_id_01_id' => 'Abonnent:innen',
], 'civicrm/demo/mailing-lists');
echo "seeded the mailing lists search page\n";

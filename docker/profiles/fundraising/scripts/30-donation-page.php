<?php
// A donation page with a Dummy payment processor and a quick-config price set,
// in one transaction, so a failed run leaves nothing behind.

use Civi\Api4\ContributionPage;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PriceField;
use Civi\Api4\PriceFieldValue;
use Civi\Api4\PriceSet;
use Civi\Api4\PriceSetEntity;

if (ContributionPage::get(FALSE)->addWhere('name', '=', 'spenden')->selectRowCount()->execute()->count()) {
  echo "donation page already seeded, skipping\n";
  return;
}

$page = [];
CRM_Core_Transaction::create()->run(function () use (&$page): void {
  $processors = [];
  foreach ([FALSE, TRUE] as $isTest) {
    $processors[] = PaymentProcessor::create(FALSE)
      ->addValue('name', 'demo_dummy')
      ->addValue('title', 'Testzahlung')
      ->addValue('frontend_title', 'Testzahlung')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_test', $isTest)
      ->addValue('is_active', TRUE)
      ->execute()->single()['id'];
  }

  $page = ContributionPage::create(FALSE)
    ->addValue('name', 'spenden')
    ->addValue('title', 'Spenden')
    ->addValue('frontend_title', 'Jetzt spenden')
    ->addValue('intro_text', 'Unterstützen Sie die Arbeit des Spendenwerks Musterstadt.')
    ->addValue('financial_type_id:name', 'Donation')
    ->addValue('payment_processor', [$processors[0]])
    ->addValue('is_monetary', TRUE)
    ->addValue('is_allow_other_amount', TRUE)
    ->addValue('min_amount', 5)
    ->addValue('currency', 'EUR')
    ->addValue('is_email_receipt', FALSE)
    ->addValue('is_active', TRUE)
    ->execute()->single();

  $priceSet = PriceSet::create(FALSE)
    ->addValue('name', 'spenden')
    ->addValue('title', 'Spenden')
    ->addValue('extends:name', ['CiviContribute'])
    ->addValue('financial_type_id:name', 'Donation')
    ->addValue('is_quick_config', TRUE)
    ->execute()->single();
  PriceSetEntity::create(FALSE)
    ->addValue('entity_table', 'civicrm_contribution_page')
    ->addValue('entity_id', $page['id'])
    ->addValue('price_set_id', $priceSet['id'])
    ->execute();

  $amounts = PriceField::create(FALSE)
    ->addValue('price_set_id', $priceSet['id'])
    ->addValue('name', 'contribution_amount')
    ->addValue('label', 'Betrag')
    ->addValue('html_type', 'Radio')
    ->execute()->single();
  foreach ([25, 50, 100] as $weight => $amount) {
    PriceFieldValue::create(FALSE)
      ->addValue('price_field_id', $amounts['id'])
      ->addValue('name', "betrag_{$amount}")
      ->addValue('label', "{$amount} €")
      ->addValue('amount', $amount)
      ->addValue('weight', $weight + 1)
      ->addValue('is_default', $amount === 50)
      ->addValue('financial_type_id:name', 'Donation')
      ->execute();
  }
  $other = PriceField::create(FALSE)
    ->addValue('price_set_id', $priceSet['id'])
    ->addValue('name', 'other_amount')
    ->addValue('label', 'Anderer Betrag')
    ->addValue('html_type', 'Text')
    ->addValue('is_required', FALSE)
    ->execute()->single();
  PriceFieldValue::create(FALSE)
    ->addValue('price_field_id', $other['id'])
    ->addValue('name', 'other_amount')
    ->addValue('label', 'Anderer Betrag')
    ->addValue('amount', 1)
    ->addValue('financial_type_id:name', 'Donation')
    ->execute();
});

echo "created the donation page (contribution page {$page['id']}) with a Dummy payment processor\n";

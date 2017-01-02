<?php

namespace Drupal\partial_date\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\partial_date\Plugin\DataType\PartialDateTimeComputed;

/**
 * Plugin implementation of the 'partial_date' field type.
 *
 * @FieldType(
 *   id = "partial_date",
 *   label = @Translation("Partial date and time"),
 *   description = @Translation("This field stores and renders partial dates."),
 *   module = "partial_date",
 *   default_widget = "partial_date_widget",
 *   default_formatter = "partial_date_formatter",
 * )
 */
class PartialDateTime extends FieldItemBase {

  /**
   * Cache for whether the host is a new revision.
   *
   * Set in preSave and used in update().  By the time update() is called
   * isNewRevision() for the host is always FALSE.
   *
   * @var bool
   */
  protected $newHostRevision;

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['timestamp'] = DataDefinition::create('float')
      ->setLabel(t('Timestamp'))
      ->setDescription('Contains best approximation for date value');
    $properties['txt_short'] = DataDefinition::create('string')
      ->setLabel(t('Short text'));
    $properties['txt_long'] = DataDefinition::create('string')
      ->setLabel(t('Long text'));
    //Components: 'year', 'month', 'day', 'hour', 'minute', 'second', 'timezone'
    foreach (partial_date_components() as $key => $label) {
      if ($key == 'timezone') {
        $properties[$key] = DataDefinition::create('string')
          ->setLabel($label);
      }
      else {
        $properties[$key] = DataDefinition::create('integer')
          ->setLabel($label)
          ->setDescription(t('The ' . $label . ' for the starting date component.'));
      }
    }

    /** @see \Drupal\partial_date\Plugin\Field\FieldType\PartialDateTime::setValue() */
    $properties['check_approximate'] = DataDefinition::create('boolean')
      ->setLabel(t('Check approximate'))
      ->setComputed(TRUE);

    $properties['from'] = MapDataDefinition::create()
      ->setLabel(t('From'))
      ->setClass(PartialDateTimeComputed::class)
      ->setSetting('range', 'from')
      ->setComputed(TRUE);

    $properties['data'] = MapDataDefinition::create()
      ->setLabel(t('Data'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   * Equivalent of hook_field_schema().
   *
   * This module stores a dates in a string that represents the data that the user
   * entered and a float timestamp that represents the best guess for the date.
   *
   * After tossing up the options a number of times, I've taken the conservative
   * opinion of storing all date components separately rather than storing these
   * in a singular field.
   */
  public static function schema(FieldStorageDefinitionInterface $field) {
    $schema = array(
      'columns' => array(
        'timestamp' => array(
          'type' => 'float',
          'size' => 'big',
          'description' => 'The calculated timestamp for a date stored in UTC as a float for unlimited date range support.',
          'not null' => TRUE,
          'default' => 0,
        ),
        // These are instance settings, so add to the schema for every field.
        'txt_short' => array(
          'type' => 'varchar',
          'length' => 100,
          'description' => 'A editable display field for this date for the short format.',
          'not null' => FALSE,
        ),
        'txt_long' => array(
          'type' => 'varchar',
          'length' => 255,
          'description' => 'A editable display field for this date for the long format.',
          'not null' => FALSE,
        ),
        'data' => array(
          'description' => 'The configuration data for the effect.',
          'type' => 'blob',
          'not null' => FALSE,
          'size' => 'big',
          'serialize' => TRUE,
        ),
      ),
      'indexes' => array(
        'timestamp' => array('timestamp'),
      ),
    );

    foreach (partial_date_components() as $key => $label) {
      if ($key == 'timezone') {
        $schema['columns'][$key] = array(
          'type' => 'varchar',
          'length' => 50,
          'description' => 'The ' . $label . ' for the time component.',
          'not null' => FALSE,
          'default' => NULL,
        );
      }
      else {
        $column = array(
          'type' => 'int',
          'description' => 'The ' . $label . ' for the starting date component.',
          'not null' => FALSE,
          'default' => NULL,
          'size' => ($key == 'year' ? 'big' : 'small'),
        );
        $schema['columns'][$key] = $column;
      }
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    foreach (array_keys(partial_date_components()) as $component) {
      $from = $this->from;
      if (isset($from[$component])) {
        $this->{$component} = $from[$component];
      }
    }

    $data = $this->data;
    $data['check_approximate'] = $this->check_approximate;
    $this->data = $data;
  }

  protected function deleteConfig($configName) {
    $config = \Drupal::configFactory()->getEditable($configName);
    if (isset($config)) {
      $config->delete();
    }
  }

  public function delete() {
    $this->deleteConfig('partial_date.settings');
    $this->deleteConfig('partial_date.format');
    parent::delete();
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
  //  return !$this->value;
    $val = $this->get('timestamp')->getValue();
    $txtShort = $this->get('txt_short')->getValue();
    $txtLong = $this->get('txt_long')->getValue();
//    $item = $this->getEntity();
//    if ((isset($item['_remove']) && $item['_remove']) || !is_array($item)) {
//      return TRUE;
//    }
//    foreach (array('from', 'to') as $base) {
//      if (empty($item[$base])) {
//        continue;
//      }
//      foreach (partial_date_components() as $key => $label) {
//        if ($key == 'timezone') {
//          continue;
//        }
//        if (isset($item[$base][$key]) && strlen($item[$base][$key])) {
//          return FALSE;
//        }
//        if (isset($item[$base][$key . '_estimate']) && strlen($item[$base][$key . '_estimate'])) {
//          return FALSE;
//        }
//      }
//    }
//
//    return !((isset($item['txt_short']) && strlen($item['txt_short'])) ||
//           (isset($item['txt_long']) && strlen($item['txt_long'])));
    return !(  isset($val) ||
              (isset($txtShort) && strlen($txtShort)) ||
              (isset($txtLong)  && strlen($txtLong) )
            );
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $elements['estimates'] = array(
      '#type' => 'details',
      '#title' => t('Base estimate values'),
      '#description' => t('These fields provide options for additional fields that can be used to represent corresponding date / time components. They define time periods where an event occured when exact details are unknown. All of these fields have the format "start|end|label", one per line, where start marks when this period started, end marks the end of the period and the label is shown to the user. Instance settings will be used whenever possible on forms, but views integration (once completed) will use the field values. Note that if used, the formatters will replace any corresponding date / time component with the options label value.'),
      '#open' => FALSE,
    );
    foreach (partial_date_components() as $key => $label) {
      if ($key == 'timezone') {
        continue;
      }
      $value = array();
      foreach($settings['estimates'][$key] as $range => $option_label) {
        $value[] = $range . '|' . $option_label;
      }
      $elements['estimates'][$key] = array(
        '#type' => 'textarea',
        '#title' => t('%label range options', array('%label' => $label), array('context' => 'datetime settings')),
        '#default_value' => implode("\n", $value),
        '#description' => t('Provide relative approximations for this date / time component.'),
        '#element_validate' => array('partial_date_field_estimates_validate_parse_options'),
        '#date_component' => $key,
      );
    }

    $elements['minimum_components'] = array(
      '#type' => 'details',
      '#title' => t('Minimum components'),
      '#description' => t('These are used to determine if the field is incomplete during validation. All possible fields are listed here, but these are only checked if enabled in the instance settings.'),
      '#open' => FALSE,
    );
    foreach (partial_date_components() as $key => $label) {
      $elements['minimum_components']['from_granularity_' . $key] = array(
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $settings['minimum_components']['from_granularity_' . $key],
      );
    }
    foreach (partial_date_components(array('timezone')) as $key => $label) {
      $elements['minimum_components']['from_estimates_' . $key] = array(
        '#type' => 'checkbox',
        '#title' => t('Estimate @date_component', array('@date_component' => $label)),
        '#default_value' => $settings['minimum_components']['from_estimates_' . $key],
      );
    }
    $elements['minimum_components']['txt_short'] = array(
      '#type' => 'checkbox',
      '#title' => t('Short date text'),
      '#default_value' => $settings['minimum_components']['txt_short'],
    );
    $elements['minimum_components']['txt_long'] = array(
      '#type' => 'checkbox',
      '#title' => t('Long date text'),
      '#default_value' => $settings['minimum_components']['txt_long'],
    );
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return array(
      'path' => '',
      'hide_blank_items' => TRUE,
      'estimates' => array(
        'year' => array(
          '-60000|1600' => t('Pre-colonial'),
          '1500|1599' => t('16th century'),
          '1600|1699' => t('17th century'),
          '1700|1799' => t('18th century'),
          '1800|1899' => t('19th century'),
          '1900|1999' => t('20th century'),
          '2000|2099' => t('21st century'),
        ),
        'month' => array(
          '11|1' => t('Winter'),
          '2|4' => t('Spring'),
          '5|7' => t('Summer'),
          '8|10' => t('Autumn'),
        ),
        'day' => array(
          '0|12' => t('The start of the month'),
          '10|20' => t('The middle of the month'),
          '18|31' => t('The end of the month'),
        ),
        'hour' => array(
          '6|18' => t('Day time'),
          '6|12' => t('Morning'),
          '12|13' => t('Noon'),
          '12|18' => t('Afternoon'),
          '18|22' => t('Evening'),
          '0|1' => t('Midnight'),
          '18|6' => t('Night'),
        ),
        'minute' => array(),
        'second' => array(),
      ),
      'minimum_components' => array(
        'from_granularity_year' => FALSE,
        'from_granularity_month' => FALSE,
        'from_granularity_day' => FALSE,
        'from_granularity_hour' => FALSE,
        'from_granularity_minute' => FALSE,
        'from_granularity_second' => FALSE,
        'from_granularity_timezone' => FALSE,
        'from_estimate_year' => FALSE,
        'from_estimate_month' => FALSE,
        'from_estimate_day' => FALSE,
        'from_estimate_hour' => FALSE,
        'from_estimate_minute' => FALSE,
        'from_estimate_second' => FALSE,
        'txt_short' => FALSE,
        'txt_long' => FALSE,
      ),
    ) + parent::defaultFieldSettings();
  }


  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    // Treat the values as property value of the main property, if no array is
    // given.
    if (isset($values) && !is_array($values)) {
      $values = [static::mainPropertyName() => $values];
    }
    if (isset($values)) {
      $values += [
        'data' => [],
      ];
    }
    // Unserialize the data property.
    // @todo The storage controller should take care of this, see
    //   https://www.drupal.org/node/2414835
    if (is_string($values['data'])) {
      $values['data'] = unserialize($values['data']);
    }
    // Instead of using a separate class for the 'check_approximate' computed
    // property, we just set it here, as we have the value of the 'data'
    // property available anyway.
    if (isset($values['data']['check_approximate'])) {
      $this->writePropertyValue('check_approximate', $values['data']['check_approximate']);
    }
    parent::setValue($values, $notify);
  }

}

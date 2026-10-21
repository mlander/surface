<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface_examples\Venues;

/**
 * Example 2, the classic way: the same five settings as a config form.
 *
 * Written as well as core allows today, not to lose, as example 1's twin
 * is: #config_target reads and writes each setting, so the parent's
 * submitForm() saves them. What depends on what is written by hand: the
 * venue and the room rebuild the room and the capacity over #ajax,
 * buildForm() reads the submitted venue and room from the form state,
 * and validateForm() refuses a room the venue does not offer and a
 * capacity the room cannot hold, in the words example 2's resource uses.
 * It writes the same config object, so the two forms edit one thing.
 */
final class RegistrationStep2ClassicForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'data_surface_examples_registration_step2_classic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['data_surface_examples.registration_step2'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stored = $this->config('data_surface_examples.registration_step2');
    $venue = $form_state->getValue('venue') ?? $stored->get('venue');
    $room = $form_state->getValue('room') ?? $stored->get('room');
    if (($form_state->getTriggeringElement()['#name'] ?? NULL) === 'venue') {
      // A new venue drops the room chosen under the old one from the input,
      // which Form API would otherwise put back, and falls back to the
      // stored room. The module's rule goes further in three places: its
      // select says it stands for the stored room, a save leaving it empty
      // is refused on the stored room rather than as required, and a
      // stored room its venue no longer offers is kept with a warning
      // rather than shown as "- Select -".
      $input = $form_state->getUserInput();
      unset($input['room']);
      $form_state->setUserInput($input);
      // The original: the parent's validateForm() copied the input onto
      // this config object on the way to the rebuild.
      $room = $stored->getOriginal('room', FALSE);
    }
    $rooms = Venues::rooms($venue);
    // NULL, not '': a required select with no default gets "- Select -".
    $room = isset($rooms[$room]) ? $room : NULL;
    $ajax = ['callback' => '::rebuildWhere', 'wrapper' => 'registration-step2-where'];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#config_target' => 'data_surface_examples.registration_step2:title',
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration open'),
      '#config_target' => 'data_surface_examples.registration_step2:open',
    ];
    $form['venue'] = [
      '#type' => 'select',
      '#title' => $this->t('Venue'),
      '#options' => Venues::VENUES,
      '#required' => TRUE,
      '#ajax' => $ajax,
      '#config_target' => 'data_surface_examples.registration_step2:venue',
    ];
    $form['where'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'registration-step2-where'],
    ];
    $form['where']['room'] = [
      '#type' => 'select',
      '#title' => $this->t('Room'),
      '#options' => $rooms,
      '#default_value' => $room,
      '#required' => TRUE,
      '#ajax' => $ajax,
      '#config_target' => 'data_surface_examples.registration_step2:room',
    ];
    // No #min or #max: validateForm() holds the bounds, so the refusal is
    // the resource's sentence and not the number element's own.
    $form['where']['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#step' => 1,
      '#config_target' => 'data_surface_examples.registration_step2:capacity',
    ];
    if ($room !== NULL) {
      $form['where']['capacity']['#description'] = $this->t('Up to @seats for the @room.', [
        '@seats' => Venues::seats($room),
        '@room' => $rooms[$room],
      ]);
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns the room and the capacity, rebuilt for the venue and room.
   *
   * @param array $form
   *   The rebuilt form.
   *
   * @return array
   *   The part of the form the #ajax wrapper replaces.
   */
  public function rebuildWhere(array &$form): array {
    return $form['where'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $venue = (string) $form_state->getValue('venue');
    $room = (string) $form_state->getValue('room');
    $rooms = Venues::rooms($venue);
    if ($room !== '' && !isset($rooms[$room])) {
      $labels = array_values($rooms);
      $last = array_pop($labels);
      $form_state->setErrorByName('room', $this->t('The value you selected is not a valid choice. This applies when Venue is @venue. Choose @rooms or @last.', [
        '@venue' => Venues::VENUES[$venue] ?? $venue,
        '@rooms' => implode(', ', $labels),
        '@last' => $last,
      ]));
    }
    $seats = Venues::seats($room);
    $capacity = (int) $form_state->getValue('capacity');
    if ($seats !== NULL && ($capacity < 1 || $capacity > $seats)) {
      $form_state->setErrorByName('capacity', $this->t('This value should be between %min and %max. This applies when Room is @room.', [
        '%min' => 1,
        '%max' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    parent::validateForm($form, $form_state);
  }

}

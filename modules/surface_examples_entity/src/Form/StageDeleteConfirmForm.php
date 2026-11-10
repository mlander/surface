<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\surface\Operation\OperationRunnerInterface;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface_examples_entity\Plugin\Action\StageDeleteAction;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Confirms the deletes the bulk action staged, and applies the stage.
 *
 * The page reads the selection StageDeleteAction kept in the private
 * temp store: the stage's id and the ids it staged a delete of. It lists
 * the selected nodes from what is stored, since each is there until the
 * stage is applied, and names the stage by its label. The label is read
 * by running the stage's `read`, which the module resolves to the
 * update's context, through the runner with no values: a run that
 * writes nothing and answers what is stored in the result's values. The
 * read tool and the HTTP door read stored values through the storage's
 * `load()` instead; the page keeps to the runner, as docs/staging.md's
 * From PHP shows. Before it asks the person, the page asks the stage what
 * it holds: it runs the stage's `changes` through the runner and reads
 * `total` from the run's outputs. With `total` 0 (the stage was
 * applied through another door, which empties it, or holds no write) no
 * delete is left to confirm, so the selection is cleared and the account
 * sent back to the content list. A stage that names nothing clears the
 * selection; one the account may not read sends it back to the content
 * list with the selection kept.
 *
 * Confirming runs the stage's `apply` through the runner for the
 * current user, the stage's owner: every delete is checked again (its
 * access, and that the node did not change since it was staged) and all
 * are made in one transaction, or none. A refusal of a write is shown
 * one line per write, `/writes/<position>`, and the stage and the
 * selection are kept. A `conflict` (a selected node changed since it was
 * staged) or a `not_found` (one was removed elsewhere) never clears: the
 * stage keeps the token it took and refuses a second delete of the node,
 * so the page says to select the content again on the content list,
 * which opens a new stage and replaces the selection. Any other refusal,
 * an `access` one among them, clears once its cause does, and the page
 * can then be submitted again. A refusal of the whole stage,
 * `does_not_apply` at no path (it was applied through another door, or
 * holds nothing, since the page asked), clears the selection and goes back
 * to the content list with its message. A stage that changed while it was
 * being applied (StageRaceException) is refused with a message to try
 * again; the page is then built again, so the selection is kept when the
 * lock was not had or a selected item changed, and cleared when the stage
 * was applied, expired or discarded by then. A committed apply clears the
 * selection.
 *
 * Cancel is a link, and a link may not discard: the stage is left to
 * expire, and the next selection replaces this one in the temp store.
 *
 * The page reads the temp store, so it is never cached.
 *
 * Decision: see
 * docs/decisions.md#a-destructive-bulk-operation-stages-then-confirms-over-the-stage.
 */
final class StageDeleteConfirmForm extends ConfirmFormBase {

  /**
   * The selection the action kept, or NULL when there is none.
   *
   * @var array{stage: string, ids: list<int|string>}|null
   */
  protected ?array $selection = NULL;

  /**
   * The stage's label, read once the selection is.
   */
  protected string $stageLabel = '';

  /**
   * Constructs a StageDeleteConfirmForm.
   *
   * @param \Drupal\surface\Operation\OperationRunnerInterface $runner
   *   The runner, which runs the stage's operations.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store, where the action kept the selection.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account the page is shown to, which owns the stage.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the selected nodes.
   */
  public function __construct(
    protected readonly OperationRunnerInterface $runner,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('surface.runner'),
      $container->get('tempstore.private'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'surface_examples_entity_stage_delete_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->formatPlural(count($this->selection['ids'] ?? []), 'Delete 1 item?', 'Delete @count items?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The deletions are staged in %stage, and nothing is removed until you confirm. Each is checked again when it is made, and they are made together or not at all.', [
      '%stage' => $this->stageLabel,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   *
   * The content list, which core's system module serves on every site.
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('system.admin_content');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array|RedirectResponse {
    $this->selection = $this->selection();
    if ($this->selection === NULL) {
      $this->messenger()->addWarning($this->t('Nothing is staged for deletion: select the content to delete on the content list first.'));
      return $this->leave();
    }
    // The label first, then what the stage holds.
    $answers = [];
    foreach (['read', 'changes'] as $operation) {
      $answer = $this->ask($operation, $this->selection['stage']);
      if ($answer === NULL) {
        $this->store()->delete(StageDeleteAction::KEY);
        $this->messenger()->addWarning($this->t('The stage of this selection expired or was discarded, so nothing is staged for deletion: select the content again.'));
        return $this->leave();
      }
      if ($answer === FALSE) {
        $this->messenger()->addError($this->t('You may not read the stage of this selection.'));
        return $this->leave();
      }
      if ($answer instanceof SurfaceResult) {
        foreach ($answer->violations as $violation) {
          $this->messenger()->addError(ResultDocument::message($violation, FALSE));
        }
        return $this->leave();
      }
      $answers[$operation] = $answer;
    }
    // Applied through another door (the only operation that empties a stage),
    // or an open stage with no write: no delete is left to confirm or to keep.
    if (($answers['changes']['outputs']['total'] ?? NULL) === 0) {
      $this->store()->delete(StageDeleteAction::KEY);
      $this->messenger()->addWarning($this->t('The stage of this selection holds nothing to apply: it was applied or emptied. Select the content again to stage removals afresh.'));
      return $this->leave();
    }
    $this->stageLabel = (string) ($answers['read']['values']['label'] ?? '');
    $ids = $this->selection['ids'];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $items = [];
    foreach ($nodes as $node) {
      $items[] = ['#plain_text' => (string) ($node->label() ?? $node->id())];
    }
    $form['still_stored'] = [
      '#markup' => '<p>' . $this->t('Each is stored until the stage is applied.') . '</p>',
    ];
    $form['entities'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
    $gone = count($ids) - count($nodes);
    if ($gone > 0) {
      $form['gone'] = [
        '#markup' => '<p>' . $this->formatPlural($gone, 'One selected item no longer loads; its delete stays in the stage, and applying it is refused.', '@count selected items no longer load; their deletes stay in the stage, and applying them is refused.') . '</p>',
      ];
    }
    $form = parent::buildForm($form, $form_state);
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selection = $this->selection ?? $this->selection();
    if ($selection === NULL) {
      $this->messenger()->addWarning($this->t('Nothing is staged for deletion: select the content to delete on the content list first.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    try {
      $run = $this->runner->run(StageResource::ID, 'apply', ['id' => $selection['stage']], values: [], account: $this->currentUser);
    }
    catch (StageRaceException) {
      $this->messenger()->addError($this->t('The stage changed while it was being applied (another request, an edit to a selected item, or the stage expired, was discarded or was applied); try again.'));
      $form_state->setRebuild();
      return;
    }
    // The runner tells every refusal of the stage's own operations, so a
    // gone stage and a refused one are told apart by the public fields.
    if ($run->refusal()?->category === RefusalCategory::NamesNothing) {
      $this->store()->delete(StageDeleteAction::KEY);
      $this->messenger()->addError($this->t('The stage of this selection expired or was discarded, so nothing was deleted: select the content again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    if ($run->isRefused()) {
      $this->messenger()->addError($this->t('You may not apply the stage of this selection, so nothing was deleted.'));
      $form_state->setRebuild();
      return;
    }
    if (!$run->result()->committed) {
      $this->refused($run->result(), $form_state);
      return;
    }
    $applied = $run->outputs()['applied'] ?? [];
    $this->messenger()->addStatus($this->formatPlural(count($applied), 'Deleted 1 item.', 'Deleted @count items.'));
    $this->store()->delete(StageDeleteAction::KEY);
    // The bulk form's ?destination, when it set one, wins over this.
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Shows each refusal of the apply, by its path, nothing deleted.
   *
   * A refusal of the whole stage, `does_not_apply` at no path (the stage
   * was applied through another door, or holds nothing), is shown as its
   * message alone; the selection is cleared and the page goes back to the
   * content list, since no delete is left to keep. Any other refusal is
   * shown one line per violation, the stage and the selection kept and the
   * form rebuilt, and a last line says nothing was deleted: after a
   * `conflict` or a `not_found`, which no resubmission clears, it says to
   * select the content again; else that the stage keeps every delete.
   *
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The refused apply.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form's state, rebuilt or redirected.
   */
  protected function refused(SurfaceResult $result, FormStateInterface $form_state): void {
    foreach ($result->violations as $violation) {
      if ($violation->fullPath() === '' && $violation->code === Violation::DOES_NOT_APPLY) {
        $this->store()->delete(StageDeleteAction::KEY);
        $this->messenger()->addError(ResultDocument::message($violation, FALSE));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }
    }
    $stays = FALSE;
    foreach ($result->violations as $violation) {
      $stays = $stays || in_array($violation->code, [Violation::CONFLICT, Violation::NOT_FOUND], TRUE);
      $path = ResultDocument::pointer($violation->fullPath());
      $arguments = [
        '@path' => $path,
        '@message' => ResultDocument::message($violation, FALSE),
        '@code' => $violation->code,
      ];
      // A refusal of the whole apply has no path.
      $this->messenger()->addError($path === '' ? $this->t('@message (@code)', $arguments) : $this->t('@path: @message (@code)', $arguments));
    }
    // A conflict or a node removed elsewhere stays: the stage keeps the
    // token it took, and refuses a second delete of the node `not_found`.
    // An access refusal clears once access is restored.
    $this->messenger()->addError($stays
      ? $this->t('Nothing was deleted. Select the content again to stage its removal afresh; the stage keeps every delete until then.')
      : $this->t('Nothing was deleted; the stage keeps every delete.'));
    $form_state->setRebuild();
  }

  /**
   * Reads the selection the action kept for the current user.
   *
   * @return array{stage: string, ids: list<int|string>}|null
   *   The stage's id and the staged ids, or NULL when there is none.
   */
  protected function selection(): ?array {
    $selection = $this->store()->get(StageDeleteAction::KEY);
    if (!is_array($selection) || !is_string($selection['stage'] ?? NULL) || !is_array($selection['ids'] ?? NULL) || $selection['ids'] === []) {
      return NULL;
    }
    return ['stage' => $selection['stage'], 'ids' => array_values($selection['ids'])];
  }

  /**
   * Runs an operation of the stage that writes nothing, through the runner.
   *
   * The stage's `read` is resolved to the update's context, so its run is
   * a rename with no values: it writes nothing and answers what is stored,
   * the label among it, in the result's values. `changes` is read only, so
   * its run stops after prepare; what it reports, `items` and `total`, is
   * read from the run's outputs.
   *
   * @param string $operation
   *   The operation, `read` or `changes`.
   * @param string $id
   *   The stage's id.
   *
   * @return array{values: array<string, mixed>, outputs: array<string, mixed>}|false|null|\Drupal\surface\Pipeline\SurfaceResult
   *   The run's values and outputs; NULL when the id names no stage, which
   *   expired or was discarded; FALSE when the current user may not read
   *   it; the refused run for any other refusal, which nothing reaches
   *   today.
   */
  protected function ask(string $operation, string $id): array|false|null|SurfaceResult {
    $run = $this->runner->run(StageResource::ID, $operation, ['id' => $id], values: [], account: $this->currentUser);
    // The runner tells every refusal of the stage's own operations, so a
    // gone stage and a refused one are told apart by the public fields.
    if ($run->refusal()?->category === RefusalCategory::NamesNothing) {
      return NULL;
    }
    // Refused by access before the run.
    if ($run->isRefused()) {
      return FALSE;
    }
    $result = $run->result();
    // The record gone since the context was built.
    foreach ($result->violations as $violation) {
      if ($violation->code === Violation::NOT_FOUND) {
        return NULL;
      }
    }
    // Unreachable today: neither run sends values, so `read` validates the
    // stored label alone, which passed the same constraints when it was
    // written (the action's own label is short and valid), `changes` takes
    // no keys, and access was answered above.
    if (!$result->isValid()) {
      return $result;
    }
    return [
      'values' => $result->values,
      'outputs' => $run->outputs(),
    ];
  }

  /**
   * Gets the private temp store collection the action keeps its selection in.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStore
   *   The collection, the current user's.
   */
  protected function store(): PrivateTempStore {
    return $this->tempStoreFactory->get(StageDeleteAction::COLLECTION);
  }

  /**
   * Goes back to the cancel page, as core's delete confirm does.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect.
   */
  protected function leave(): RedirectResponse {
    return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
  }

}

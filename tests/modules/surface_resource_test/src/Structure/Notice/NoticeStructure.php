<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Notice;

use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A notice sent by post or by email: its keys hold children, ruled out.
 *
 * The address is an attached child, and applies only to a notice sent by
 * post; the schedule has variants its cadence chooses, and applies only to
 * one sent by email. NoticeAlter mounts a signature that applies only to
 * email too.
 */
#[Structure('resource_test.notice')]
final class NoticeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('channel', Schema::enum(['post', 'email'])
      ->title('Channel')
      ->default('post')
      ->required());
    $inputs->add('cadence', Schema::string()
      ->title('Cadence')
      ->default('daily')
      ->required());
    $inputs->attach('address', NoticeAddressStructure::class, 'Address');
    $inputs->attachVariants('schedule', by: 'cadence', label: 'Schedule');
  }

  /**
   * An address applies only to a notice sent by post.
   */
  #[RefinesInput('address')]
  public static function addressOnlyByPost(string $channel): Schema|DoesNotApply {
    return $channel === 'post' ? Schema::any() : DoesNotApply::here();
  }

  /**
   * A schedule applies only to a notice sent by email.
   */
  #[RefinesInput('schedule')]
  public static function scheduleOnlyByEmail(string $channel): Schema|DoesNotApply {
    return $channel === 'email' ? Schema::any() : DoesNotApply::here();
  }

}

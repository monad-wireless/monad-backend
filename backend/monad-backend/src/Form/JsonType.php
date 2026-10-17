<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A textarea bound to a JSON column.
 *
 * EasyAdmin's ArrayField edits a flat list of scalars, which is the wrong shape for the fields
 * that matter here: a quest step's `config` is nested and step-type-specific, and a skip record's
 * `metadata` is whatever the device sent. Rendering those as a list either flattens them or drops
 * them, so they get the raw document instead — pretty-printed going out, validated coming in.
 *
 * Invalid JSON fails the form rather than silently persisting a string, because a `config` that
 * round-trips to the wrong type breaks the step on a participant's phone, in the field, where the
 * error surfaces as an app that does nothing.
 */
class JsonType extends AbstractType implements DataTransformerInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['rows' => 10, 'class' => 'font-monospace'],
        ]);

        // EasyAdmin sees a Doctrine `json` property and hands the field CollectionType's options,
        // whatever form type was actually asked for. They are meaningless to a textarea, but an
        // undefined option is a hard error, so they are accepted and ignored rather than fought.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    /** array (entity) -> string (textarea) */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** string (textarea) -> array (entity) */
    public function reverseTransform(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TransformationFailedException(
                'Invalid JSON',
                0,
                null,
                'This is not valid JSON: ' . json_last_error_msg(),
            );
        }

        // A bare scalar is valid JSON but not a valid column value — the columns are all
        // json arrays/objects, and Doctrine would hand the entity an int where it declared array.
        if (!is_array($decoded)) {
            throw new TransformationFailedException(
                'JSON must be an object or array',
                0,
                null,
                'Enter a JSON object or array, not a bare value.',
            );
        }

        return $decoded;
    }
}

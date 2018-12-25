<?php

namespace CodeMasterLTM\TranslationManager;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use CodeMasterLTM\TranslationManager\Models\Translation;
use Illuminate\Support\Collection;

class Controller extends BaseController
{
    /** @var \CodeMasterLTM\TranslationManager\Manager */
    protected $manager;

    /**
     * Controller constructor.
     * @param Manager $manager
     */
    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @param null $group
     * @return mixed
     */
    public function getIndex($group = null)
    {
        $locales = $this->manager->getLocales();
        $groups = Translation::groupBy('group');
        $excludedGroups = $this->manager->getConfig('exclude_groups');
        if ($excludedGroups) {
            $groups->whereNotIn('group', $excludedGroups);
        }

        $groups = $groups->select('group')->orderBy('group')->get()->pluck('group', 'group');
        if ($groups instanceof Collection) {
            $groups = $groups->all();
        }
        $groups = ['' => 'Choose a group'] + $groups;
        $numChanged = Translation::where('group', $group)->where('status', Translation::STATUS_CHANGED)->count();


        $allTranslations = Translation::where('group', $group)->orderBy('key', 'asc')->get();
        $numTranslations = count($allTranslations);
        $translations = [];
        foreach ($allTranslations as $translation) {
            $translations[$translation->key][$translation->locale] = $translation;
        }

        return view('translation-manager::index')
            ->with('translations', $translations)
            ->with('locales', $locales)
            ->with('groups', $groups)
            ->with('group', $group)
            ->with('numTranslations', $numTranslations)
            ->with('numChanged', $numChanged)
            ->with('editUrl', action('\CodeMasterLTM\TranslationManager\Controller@postEdit', [$group]))
            ->with('deleteEnabled', $this->manager->getConfig('delete_enabled'));
    }

    /**
     * @param null $group
     * @return mixed
     */
    public function getView($group = null)
    {
        return $this->getIndex($group);
    }

    /**
     * @return array
     */
    protected function loadLocales()
    {
        //Set the default locale as the first one.
        $locales = Translation::groupBy('locale')
            ->select('locale')
            ->get()
            ->pluck('locale');

        if ($locales instanceof Collection) {
            $locales = $locales->all();
        }
        $locales = array_merge([config('app.locale')], $locales);
        return array_unique($locales);
    }

    /**
     * @param null $group
     * @return mixed
     */
    public function postAdd($group = null)
    {
        $keys = explode("\n", request()->get('keys'));

        foreach ($keys as $key) {
            $key = trim($key);
            if ($group && $key) {
                $this->manager->missingKey('*', $group, $key);
            }
        }
        return redirect()->back();
    }

    /**
     * @param null $group
     * @return array
     */
    public function postEdit($group = null)
    {
        if (!in_array($group, $this->manager->getConfig('exclude_groups'))) {
            $name = request()->get('name');
            $value = request()->get('value');

            list($locale, $key) = explode('|', $name, 2);
            $translation = Translation::firstOrNew([
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
            ]);
            $translation->value = (string)$value ?: null;
            $translation->status = Translation::STATUS_CHANGED;
            $translation->save();
            return array('status' => 'ok');
        }
    }

    /**
     * @param null $group
     * @param $key
     * @return array
     */
    public function postDelete($group = null, $key)
    {
        if (!in_array($group, $this->manager->getConfig('exclude_groups')) && $this->manager->getConfig('delete_enabled')) {
            Translation::where('group', $group)->where('key', $key)->delete();
            return ['status' => 'ok'];
        }
    }

    /**
     * @param Request $request
     * @return array
     */
    public function postImport(Request $request)
    {
        $replace = $request->get('replace', false);
        $counter = $this->manager->importTranslations($replace);

        return ['status' => 'ok', 'counter' => $counter];
    }

    /**
     * @return array
     */
    public function postFind()
    {
        $numFound = $this->manager->findTranslations();

        return ['status' => 'ok', 'counter' => (int)$numFound];
    }

    /**
     * @param null $group
     * @return array
     */
    public function postPublish($group = null)
    {
        $json = false;

        if ($group === '_json') {
            $json = true;
        }

        $this->manager->exportTranslations($group, $json);

        return ['status' => 'ok'];
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function postAddGroup(Request $request)
    {
        $group = str_replace(".", '', $request->input('new-group'));
        if ($group) {
            return redirect()->action('\CodeMasterLTM\TranslationManager\Controller@getView', $group);
        } else {
            return redirect()->back();
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function postAddLocale(Request $request)
    {
        $locales = $this->manager->getLocales();
        $newLocale = str_replace([], '-', trim($request->input('new-locale')));
        if (!$newLocale || in_array($newLocale, $locales)) {
            return redirect()->back();
        }
        $this->manager->addLocale($newLocale);
        return redirect()->back();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function postRemoveLocale(Request $request)
    {
        foreach ($request->input('remove-locale', []) as $locale => $val) {
            $this->manager->removeLocale($locale);
        }
        return redirect()->back();
    }
}

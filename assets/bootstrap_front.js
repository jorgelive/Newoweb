import { startStimulusApp } from '@symfony/stimulus-bridge';

// Registers Stimulus controllers from controllers.json and in the controllers/ directory
export const adminStimulus = startStimulusApp(require.context(
    './controllers',
    true,
    /^\.\/front\/.*_controller\.[jt]sx?$/
));

// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

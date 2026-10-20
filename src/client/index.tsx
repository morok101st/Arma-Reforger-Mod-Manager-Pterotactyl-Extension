import { definePterodactylExtension } from '@pterodactyl/sdk';
export default definePterodactylExtension({
  setup({ screens }) {
    screens.register('modsets', () => import('./screens/ArmmScreen'));
  },
});

pimcore.registerNS("pimcore.plugin.StoreSyndicatorBundle");

pimcore.plugin.StoreSyndicatorBundle = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return 'pimcore.plugin.StoreSyndicatorBundle';
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);
    }

});

var StoreSyndicatorBundle = new pimcore.plugin.StoreSyndicatorBundle();

pimcore.registerNS("pimcore.plugin.datahub.adapter.storeExporterDataObject");
pimcore.plugin.datahub.adapter.storeExporterDataObject = Class.create(pimcore.plugin.datahub.adapter.graphql, {

    createConfigPanel: function(data) {
        let fieldPanel = new pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject(data, this);
    },

    openConfiguration: function (id) {
        var existingPanel = Ext.getCmp("plugin_pimcore_datahub_configpanel_panel_" + id);
        if (existingPanel) {
            this.configPanel.editPanel.setActiveTab(existingPanel);
            return;
        }

        Ext.Ajax.request({
            url: Routing.generate('pimcore_storeexporter_configdataobject_get'),
            params: {
                name: id
            },
            success: function (response) {
                let data = Ext.decode(response.responseText);
                this.createConfigPanel(data);
                pimcore.layout.refresh();
            }.bind(this)
        });
    }
});
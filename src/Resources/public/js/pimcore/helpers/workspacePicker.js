pimcore.registerNS("pimcore.plugin.storeExporterDataObject.helpers.workspace.object");
pimcore.plugin.storeExporterDataObject.helpers.workspace.object = Class.create(pimcore.plugin.datahub.workspace.abstract, {

    type: "object",
    initialize: function (parent) {
        this.parent = parent;
        this.workspaces = this.parent.data.products.products;
    },
});